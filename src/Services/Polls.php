<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Enums\PollRefusal;
use Filagora\Forums\Enums\PollStatus;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Exceptions\PollRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Poll;
use Filagora\Forums\Models\PollOption;
use Filagora\Forums\Models\PollQuestion;
use Filagora\Forums\Models\PollVote;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Services\Concerns\ChecksModerator;
use Filagora\Forums\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Polls on threads. Moderators add one to a thread (one per thread), change its questions while
 * nobody has voted, and close or reopen it; each of those is logged on the thread
 * (`poll_created`, `poll_updated`, `poll_closed`, `poll_reopened`) and announced as a
 * `ThreadModerated` event. Anyone who may see the thread votes once, all questions at a time, and
 * the vote is locked. Moderator actions refuse with `ModerationNotPermitted`; everything else
 * with `PollRefused` and a reason.
 *
 * Questions arrive as submitted: each is `['prompt' => ..., 'is_multi_select' => ..., 'options' => [['label' => ...], ...]]`,
 * checked here, so the shape is not assumed.
 *
 * @phpstan-type QuestionInput array<string, mixed>
 */
class Polls
{
    use ChecksModerator;

    public const MAX_QUESTIONS = 20;

    public const MAX_OPTIONS = 20;

    public const MAX_PROMPT = 500;

    public const MAX_LABEL = 255;

    public function __construct(private readonly ForumAccess $access) {}

    /**
     * @param  array<int|string, QuestionInput>  $questions
     */
    public function create(Authenticatable $moderator, Thread $thread, array $questions): Poll
    {
        $this->authorize($moderator);

        if (Models::class('poll')::query()->where('thread_id', $thread->getKey())->exists()) {
            throw new PollRefused(PollRefusal::ALREADY_HAS_POLL);
        }

        $questions = $this->validated($questions);

        /** @var Poll $poll */
        $poll = DB::transaction(function () use ($moderator, $thread, $questions): Poll {
            /** @var Poll $poll */
            $poll = Models::class('poll')::query()->create([
                'thread_id' => $thread->getKey(),
                'created_by_id' => $moderator->getAuthIdentifier(),
                'status' => PollStatus::OPEN,
            ]);

            $this->writeQuestions($poll, $questions);

            return $poll;
        });

        $this->log($moderator, $thread, 'poll_created');

        return $poll;
    }

    /**
     * Replace every question and option. Refused once anyone has voted: there would be votes
     * left pointing at options that no longer exist.
     *
     * @param  array<int|string, QuestionInput>  $questions
     */
    public function replaceQuestions(Authenticatable $moderator, Poll $poll, array $questions): Poll
    {
        $this->authorize($moderator);

        $questions = $this->validated($questions);

        DB::transaction(function () use ($poll, $questions): void {
            if ($poll->votes()->lockForUpdate()->exists()) {
                throw new PollRefused(PollRefusal::HAS_VOTES);
            }

            $poll->questions()->delete();
            $this->writeQuestions($poll, $questions);
        });

        $this->log($moderator, $this->threadOf($poll), 'poll_updated');

        return $poll->refresh();
    }

    public function close(Authenticatable $moderator, Poll $poll): Poll
    {
        $this->authorize($moderator);

        if (! $poll->isOpen()) {
            throw new PollRefused(PollRefusal::WRONG_STATE);
        }

        $poll->forceFill(['status' => PollStatus::CLOSED, 'closed_at' => now(), 'closed_by_id' => $moderator->getAuthIdentifier()])->save();
        $this->log($moderator, $this->threadOf($poll), 'poll_closed');

        return $poll;
    }

    public function reopen(Authenticatable $moderator, Poll $poll): Poll
    {
        $this->authorize($moderator);

        if (! $poll->isClosed()) {
            throw new PollRefused(PollRefusal::WRONG_STATE);
        }

        $poll->forceFill(['status' => PollStatus::OPEN, 'closed_at' => null, 'closed_by_id' => null])->save();
        $this->log($moderator, $this->threadOf($poll), 'poll_reopened');

        return $poll;
    }

    /**
     * Cast a vote. `$answers` maps each question id to the option ids chosen. Every question must
     * be answered; a single-choice question takes exactly one option, a multiple-choice question
     * at least one; options must belong to the question they are given for. All or nothing.
     *
     * @param  array<int|string, list<int|string>>  $answers
     */
    public function vote(Authenticatable $user, Poll $poll, array $answers): PollVote
    {
        $thread = $this->threadOf($poll);
        $forum = $thread->forum;

        assert($forum instanceof Forum);

        if (! $this->access->canViewForum($forum, $user) || ! $this->access->canAccessThread($thread, $user)) {
            throw new PollRefused(PollRefusal::NOT_VISIBLE);
        }

        if (! $poll->isOpen()) {
            throw new PollRefused(PollRefusal::CLOSED);
        }

        $chosen = $this->chosenOptions($poll, $answers);

        return DB::transaction(function () use ($poll, $user, $chosen): PollVote {
            if ($poll->votes()->where('user_id', $user->getAuthIdentifier())->lockForUpdate()->exists()) {
                throw new PollRefused(PollRefusal::ALREADY_VOTED);
            }

            /** @var PollVote $vote */
            $vote = Models::class('poll_vote')::query()->create(['poll_id' => $poll->getKey(), 'user_id' => $user->getAuthIdentifier()]);

            foreach ($chosen as $questionId => $optionIds) {
                foreach ($optionIds as $optionId) {
                    Models::class('poll_vote_answer')::query()->create([
                        'poll_vote_id' => $vote->getKey(),
                        'poll_question_id' => $questionId,
                        'poll_option_id' => $optionId,
                    ]);
                }
            }

            return $vote;
        });
    }

    /**
     * Results show to staff, to someone who has voted, and to everyone once the poll is closed.
     */
    public function canSeeResults(Poll $poll, ?Authenticatable $user): bool
    {
        return $poll->isClosed() || $poll->hasVoted($user) || $this->access->actsAsStaff($user);
    }

    /**
     * The questions with each option's number of votes, and how many people voted.
     *
     * @return array{voters: int, questions: list<array{id: int, prompt: string, is_multi_select: bool, options: list<array{id: int, label: string, votes: int}>}>}
     */
    public function results(Poll $poll): array
    {
        $poll->load(['questions.options' => fn ($query) => $query->withCount('answers')]);

        $questions = [];

        foreach ($poll->questions as $question) {
            /** @var PollQuestion $question */
            $options = [];

            foreach ($question->options as $option) {
                /** @var PollOption $option */
                $options[] = ['id' => (int) $option->getKey(), 'label' => $option->label, 'votes' => (int) $option->getAttribute('answers_count')];
            }

            $questions[] = ['id' => (int) $question->getKey(), 'prompt' => $question->prompt, 'is_multi_select' => $question->is_multi_select, 'options' => $options];
        }

        return ['voters' => $poll->votes()->count(), 'questions' => $questions];
    }

    /**
     * @param  array<int|string, list<int|string>>  $answers
     * @return array<int, list<int>> question id => option ids
     */
    private function chosenOptions(Poll $poll, array $answers): array
    {
        $poll->loadMissing('questions.options');

        $chosen = [];

        foreach ($poll->questions as $question) {
            /** @var PollQuestion $question */
            $given = $answers[$question->getKey()] ?? null;
            $ids = is_array($given) ? array_values(array_unique(array_map('intval', array_filter($given, 'is_numeric')))) : [];
            $allowed = $question->options->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $valid = $ids !== []
                && array_diff($ids, $allowed) === []
                && ($question->is_multi_select || count($ids) === 1);

            if (! $valid) {
                throw new PollRefused(PollRefusal::INVALID_ANSWERS);
            }

            $chosen[(int) $question->getKey()] = $ids;
        }

        return $chosen;
    }

    /**
     * @param  array<int|string, QuestionInput>  $questions
     * @return list<array{prompt: string, is_multi_select: bool, options: list<string>}>
     */
    private function validated(array $questions): array
    {
        $t = static fn (string $key, array $replace = []): string => __("filagora-forums::polls.invalid.{$key}", $replace);

        if ($questions === [] || count($questions) > self::MAX_QUESTIONS) {
            throw new PollRefused(PollRefusal::INVALID_QUESTIONS, $t('question_count', ['max' => self::MAX_QUESTIONS]));
        }

        $clean = [];

        foreach (array_values($questions) as $index => $question) {
            $number = $index + 1;
            $prompt = trim((string) ($question['prompt'] ?? ''));
            $labels = array_values(array_map(static fn ($option): string => trim((string) (is_array($option) ? ($option['label'] ?? '') : $option)), (array) ($question['options'] ?? [])));

            if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT) {
                throw new PollRefused(PollRefusal::INVALID_QUESTIONS, $t('prompt', ['number' => $number, 'max' => self::MAX_PROMPT]));
            }

            if (count($labels) < 2 || count($labels) > self::MAX_OPTIONS) {
                throw new PollRefused(PollRefusal::INVALID_QUESTIONS, $t('option_count', ['number' => $number, 'max' => self::MAX_OPTIONS]));
            }

            foreach ($labels as $label) {
                if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                    throw new PollRefused(PollRefusal::INVALID_QUESTIONS, $t('label', ['number' => $number, 'max' => self::MAX_LABEL]));
                }
            }

            if (count(array_unique(array_map('mb_strtolower', $labels))) !== count($labels)) {
                throw new PollRefused(PollRefusal::INVALID_QUESTIONS, $t('repeated', ['number' => $number]));
            }

            $clean[] = ['prompt' => $prompt, 'is_multi_select' => filter_var($question['is_multi_select'] ?? false, FILTER_VALIDATE_BOOLEAN), 'options' => $labels];
        }

        return $clean;
    }

    /**
     * @param  list<array{prompt: string, is_multi_select: bool, options: list<string>}>  $questions
     */
    private function writeQuestions(Poll $poll, array $questions): void
    {
        foreach ($questions as $position => $question) {
            /** @var PollQuestion $created */
            $created = Models::class('poll_question')::query()->create([
                'poll_id' => $poll->getKey(),
                'prompt' => $question['prompt'],
                'is_multi_select' => $question['is_multi_select'],
                'sort_order' => $position,
            ]);

            foreach ($question['options'] as $optionPosition => $label) {
                Models::class('poll_option')::query()->create(['poll_question_id' => $created->getKey(), 'label' => $label, 'sort_order' => $optionPosition]);
            }
        }
    }

    private function threadOf(Poll $poll): Thread
    {
        /** @var Thread */
        return Models::class('thread')::query()->with('forum')->findOrFail($poll->thread_id);
    }

    private function log(Authenticatable $moderator, Thread $thread, string $action): void
    {
        ThreadModerationLog::query()->create([
            'thread_id' => $thread->getKey(),
            'moderator_id' => $moderator->getAuthIdentifier(),
            'action' => $action,
            'reason' => null,
        ]);

        ThreadModerated::dispatch($thread, $moderator, $action, null);
    }
}
