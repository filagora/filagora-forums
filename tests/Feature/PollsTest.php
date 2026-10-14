<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Enums\PollRefusal;
use Filagora\Forums\Enums\PollStatus;
use Filagora\Forums\Events\ThreadModerated;
use Filagora\Forums\Exceptions\ModerationNotPermitted;
use Filagora\Forums\Exceptions\PollRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Poll;
use Filagora\Forums\Models\PollOption;
use Filagora\Forums\Models\PollQuestion;
use Filagora\Forums\Models\PollVote;
use Filagora\Forums\Models\PollVoteAnswer;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\Polls;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

function pollsService(): Polls
{
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(Polls::class);

    return app(Polls::class);
}

function pollModerator(): TestUser
{
    $user = TestUser::make('Mod');
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => Abilities::MODERATE, 'grant_type' => 'manual']);

    return $user;
}

function pollThread(array $forum = []): Thread
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(array_merge(['forum_group_id' => $group->id, 'name' => 'F'.uniqid(), 'slug' => 'f-'.uniqid()], $forum));

    return Thread::create(['forum_id' => $forum->id, 'author_id' => TestUser::make('Author')->id, 'title' => 'T', 'slug' => 't-'.uniqid()])->refresh();
}

function sampleQuestions(): array
{
    return [
        ['prompt' => ' Favourite colour? ', 'is_multi_select' => false, 'options' => [['label' => 'Red'], ['label' => ' Blue ']]],
        ['prompt' => 'Which pets?', 'is_multi_select' => '1', 'options' => [['label' => 'Cat'], ['label' => 'Dog'], ['label' => 'Fish']]],
    ];
}

function samplePoll(?Thread $thread = null): Poll
{
    return pollsService()->create(pollModerator(), $thread ?? pollThread(), sampleQuestions());
}

function answersFor(Poll $poll, array $pick = []): array
{
    $poll->load('questions.options');
    $answers = [];

    foreach ($poll->questions as $i => $question) {
        $answers[$question->id] = array_map(fn (int $n): int => $question->options[$n]->id, $pick[$i] ?? [0]);
    }

    return $answers;
}

function pollRefusal(callable $call): ?PollRefusal
{
    try {
        $call();
    } catch (PollRefused $e) {
        return $e->reason;
    }

    return null;
}

// ------------------------------------------------------------------ creating

it('creates a poll on a thread with trimmed questions and ordered options, logged and announced', function () {
    Event::fake([ThreadModerated::class]);
    $moderator = pollModerator();
    $thread = pollThread();

    $poll = pollsService()->create($moderator, $thread, sampleQuestions());

    expect($poll->status)->toBe(PollStatus::OPEN)
        ->and($poll->thread_id)->toBe($thread->id)
        ->and($poll->created_by_id)->toBe($moderator->id)
        ->and($poll->questions)->toHaveCount(2)
        ->and($poll->questions[0]->prompt)->toBe('Favourite colour?')
        ->and($poll->questions[0]->is_multi_select)->toBeFalse()
        ->and($poll->questions[1]->is_multi_select)->toBeTrue()
        ->and($poll->questions[0]->options->pluck('label')->all())->toBe(['Red', 'Blue'])
        ->and($poll->questions[1]->options->pluck('sort_order')->all())->toBe([0, 1, 2]);

    expect(ThreadModerationLog::query()->where('thread_id', $thread->id)->pluck('action')->all())->toBe(['poll_created']);
    Event::assertDispatched(ThreadModerated::class, fn ($e) => $e->action === 'poll_created' && $e->moderator->is($moderator));
});

it('is for moderators only', function () {
    expect(fn () => pollsService()->create(TestUser::make('Member'), pollThread(), sampleQuestions()))->toThrow(ModerationNotPermitted::class);
    expect(Poll::count())->toBe(0);
});

it('gives a thread one poll only', function () {
    $thread = pollThread();
    samplePoll($thread);

    expect(pollRefusal(fn () => pollsService()->create(pollModerator(), $thread, sampleQuestions())))->toBe(PollRefusal::ALREADY_HAS_POLL)
        ->and(Poll::count())->toBe(1);
});

it('refuses malformed questions with a message that says which', function () {
    $service = pollsService();
    $moderator = pollModerator();
    $options = [['label' => 'A'], ['label' => 'B']];
    $long = str_repeat('x', 501);
    $cases = [
        'no questions' => [[], 'between 1 and 20'],
        'too many questions' => [array_fill(0, 21, ['prompt' => 'Q', 'options' => $options]), 'between 1 and 20'],
        'blank prompt' => [[['prompt' => '  ', 'options' => $options]], 'Question 1 needs a prompt'],
        'long prompt' => [[['prompt' => $long, 'options' => $options]], 'Question 1 needs a prompt'],
        'one option' => [[['prompt' => 'Q', 'options' => [['label' => 'A']]]], 'between 2 and 20 options'],
        'too many options' => [[['prompt' => 'Q', 'options' => array_map(fn ($i) => ['label' => "O{$i}"], range(1, 21))]], 'between 2 and 20 options'],
        'blank label' => [[['prompt' => 'Q', 'options' => [['label' => 'A'], ['label' => ' ']]]], 'needs a label'],
        'long label' => [[['prompt' => 'Q', 'options' => [['label' => 'A'], ['label' => str_repeat('x', 256)]]]], 'needs a label'],
        'repeated option' => [[['prompt' => 'Q', 'options' => [['label' => 'Red'], ['label' => ' red ']]]], 'must not repeat'],
        'second question bad' => [[['prompt' => 'Q', 'options' => $options], ['prompt' => '', 'options' => $options]], 'Question 2'],
    ];

    foreach ($cases as $name => [$questions, $message]) {
        try {
            $service->create($moderator, pollThread(), $questions);
            $failed = "{$name} was accepted";
        } catch (PollRefused $e) {
            $failed = $e->reason === PollRefusal::INVALID_QUESTIONS && str_contains($e->getMessage(), $message) ? null : "{$name}: {$e->getMessage()}";
        }

        expect($failed)->toBeNull();
    }

    expect(Poll::count())->toBe(0);
});

it('accepts the limits', function () {
    $questions = array_fill(0, 20, ['prompt' => str_repeat('p', 500), 'options' => array_map(fn ($i) => ['label' => str_repeat((string) ($i % 10), 255 - 1).chr(97 + $i)], range(0, 19))]);

    $poll = pollsService()->create(pollModerator(), pollThread(), $questions);

    expect($poll->questions)->toHaveCount(20)->and(PollOption::count())->toBe(400);
});

// ------------------------------------------------------------------ editing

it('replaces the questions while no one has voted, and not after', function () {
    $moderator = pollModerator();
    $poll = samplePoll();

    $poll = pollsService()->replaceQuestions($moderator, $poll, [['prompt' => 'New?', 'options' => [['label' => 'Yes'], ['label' => 'No']]]]);

    expect($poll->questions)->toHaveCount(1)
        ->and(PollQuestion::count())->toBe(1)
        ->and(PollOption::count())->toBe(2)
        ->and(ThreadModerationLog::where('action', 'poll_updated')->count())->toBe(1);

    pollsService()->vote(TestUser::make('Voter'), $poll, answersFor($poll));

    expect(pollRefusal(fn () => pollsService()->replaceQuestions($moderator, $poll, sampleQuestions())))->toBe(PollRefusal::HAS_VOTES)
        ->and(PollQuestion::count())->toBe(1);
});

it('keeps the old questions when the new ones are invalid', function () {
    $poll = samplePoll();

    expect(pollRefusal(fn () => pollsService()->replaceQuestions(pollModerator(), $poll, [['prompt' => '', 'options' => []]])))->toBe(PollRefusal::INVALID_QUESTIONS)
        ->and(PollQuestion::count())->toBe(2);
});

it('closes and reopens a poll, logged, and refuses a no-op', function () {
    $moderator = pollModerator();
    $poll = samplePoll();

    pollsService()->close($moderator, $poll);
    expect($poll->refresh()->status)->toBe(PollStatus::CLOSED)->and($poll->closed_at)->not->toBeNull()->and($poll->closed_by_id)->toBe($moderator->id)
        ->and(pollRefusal(fn () => pollsService()->close($moderator, $poll)))->toBe(PollRefusal::WRONG_STATE);

    pollsService()->reopen($moderator, $poll);
    expect($poll->refresh()->status)->toBe(PollStatus::OPEN)->and($poll->closed_at)->toBeNull()->and($poll->closed_by_id)->toBeNull()
        ->and(pollRefusal(fn () => pollsService()->reopen($moderator, $poll)))->toBe(PollRefusal::WRONG_STATE)
        ->and(ThreadModerationLog::orderBy('id')->pluck('action')->all())->toBe(['poll_created', 'poll_closed', 'poll_reopened']);

    expect(fn () => pollsService()->close(TestUser::make('Member'), $poll))->toThrow(ModerationNotPermitted::class);
});

// ------------------------------------------------------------------ voting

it('records a vote: one row for the person, one answer per option chosen', function () {
    $poll = samplePoll();
    $voter = TestUser::make('Voter');

    $vote = pollsService()->vote($voter, $poll, answersFor($poll, [[1], [0, 2]]));

    expect($vote)->toBeInstanceOf(PollVote::class)
        ->and($poll->hasVoted($voter))->toBeTrue()
        ->and($poll->hasVoted(TestUser::make('Other')))->toBeFalse()
        ->and($poll->hasVoted(null))->toBeFalse()
        ->and(PollVoteAnswer::where('poll_vote_id', $vote->id)->count())->toBe(3);
});

it('locks a vote once cast', function () {
    $poll = samplePoll();
    $voter = TestUser::make('Voter');
    pollsService()->vote($voter, $poll, answersFor($poll));

    expect(pollRefusal(fn () => pollsService()->vote($voter, $poll, answersFor($poll, [[1], [1]]))))->toBe(PollRefusal::ALREADY_VOTED)
        ->and(PollVote::count())->toBe(1)
        ->and(PollVoteAnswer::count())->toBe(2);
});

it('takes no votes on a closed poll', function () {
    $poll = samplePoll();
    pollsService()->close(pollModerator(), $poll);

    expect(pollRefusal(fn () => pollsService()->vote(TestUser::make('Voter'), $poll->refresh(), answersFor($poll))))->toBe(PollRefusal::CLOSED);
});

it('refuses answers that are missing, the wrong number, or another question\'s options, and records nothing', function () {
    $poll = samplePoll();
    $poll->load('questions.options');
    [$colour, $pets] = [$poll->questions[0], $poll->questions[1]];
    $voter = TestUser::make('Voter');
    $cases = [
        'no answers' => [],
        'a question left out' => [$colour->id => [$colour->options[0]->id]],
        'two options for a single choice' => [$colour->id => [$colour->options[0]->id, $colour->options[1]->id], $pets->id => [$pets->options[0]->id]],
        'none for a multiple choice' => [$colour->id => [$colour->options[0]->id], $pets->id => []],
        'an option of another question' => [$colour->id => [$pets->options[0]->id], $pets->id => [$pets->options[1]->id]],
        'an option that does not exist' => [$colour->id => [999999], $pets->id => [$pets->options[0]->id]],
        'not an id' => [$colour->id => ['red'], $pets->id => [$pets->options[0]->id]],
    ];

    foreach ($cases as $name => $answers) {
        expect(pollRefusal(fn () => pollsService()->vote($voter, $poll, $answers)))->toBe(PollRefusal::INVALID_ANSWERS, $name);
    }

    expect(PollVote::count())->toBe(0)->and(PollVoteAnswer::count())->toBe(0);
});

it('lets a multiple choice take several options, once each, even if sent twice', function () {
    $poll = samplePoll();
    $poll->load('questions.options');
    $pets = $poll->questions[1];

    pollsService()->vote(TestUser::make('Voter'), $poll, [
        $poll->questions[0]->id => [$poll->questions[0]->options[0]->id],
        $pets->id => [$pets->options[0]->id, (string) $pets->options[0]->id, $pets->options[2]->id],
    ]);

    expect(PollVoteAnswer::where('poll_question_id', $pets->id)->pluck('poll_option_id')->all())->toBe([$pets->options[0]->id, $pets->options[2]->id]);
});

it('takes votes only from people who can see the thread', function () {
    $thread = pollThread(['visibility' => ForumVisibility::STAFF_ONLY->value]);
    $poll = samplePoll($thread);

    expect(pollRefusal(fn () => pollsService()->vote(TestUser::make('Member'), $poll, answersFor($poll))))->toBe(PollRefusal::NOT_VISIBLE);

    $staff = pollModerator();
    expect(pollsService()->vote($staff, $poll, answersFor($poll)))->toBeInstanceOf(PollVote::class);
});

// ------------------------------------------------------------------ results

it('counts votes per option and people who voted', function () {
    $poll = samplePoll();
    pollsService()->vote(TestUser::make('A'), $poll, answersFor($poll, [[0], [0, 1]]));
    pollsService()->vote(TestUser::make('B'), $poll, answersFor($poll, [[0], [1]]));
    pollsService()->vote(TestUser::make('C'), $poll, answersFor($poll, [[1], [1, 2]]));

    $results = pollsService()->results($poll);

    expect($results['voters'])->toBe(3)
        ->and(array_column($results['questions'][0]['options'], 'votes'))->toBe([2, 1])
        ->and(array_column($results['questions'][1]['options'], 'votes'))->toBe([1, 3, 1])
        ->and($results['questions'][0]['prompt'])->toBe('Favourite colour?')
        ->and($results['questions'][1]['is_multi_select'])->toBeTrue();
});

it('shows results to staff, to voters and to everyone once closed, and hides them from the rest', function () {
    $poll = samplePoll();
    $voter = TestUser::make('Voter');
    $other = TestUser::make('Other');
    pollsService()->vote($voter, $poll, answersFor($poll));

    $service = pollsService();
    expect($service->canSeeResults($poll, $voter))->toBeTrue()
        ->and($service->canSeeResults($poll, pollModerator()))->toBeTrue()
        ->and($service->canSeeResults($poll, $other))->toBeFalse()
        ->and($service->canSeeResults($poll, null))->toBeFalse();

    $service->close(pollModerator(), $poll);

    expect($service->canSeeResults($poll->refresh(), $other))->toBeTrue()->and($service->canSeeResults($poll, null))->toBeTrue();
});

it('loses its questions and votes with the thread, and keeps its votes when the creator goes', function () {
    $thread = pollThread();
    $moderator = pollModerator();
    $poll = pollsService()->create($moderator, $thread, sampleQuestions());
    pollsService()->vote(TestUser::make('Voter'), $poll, answersFor($poll));

    $moderator->delete();
    expect($poll->refresh()->created_by_id)->toBeNull()->and(PollVote::count())->toBe(1);

    $thread->delete();
    expect(Poll::count())->toBe(0)->and(PollQuestion::count())->toBe(0)->and(PollOption::count())->toBe(0)->and(PollVote::count())->toBe(0)->and(PollVoteAnswer::count())->toBe(0);
});
