<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Poll;
use Filagora\Forums\Models\PollOption;
use Filagora\Forums\Models\PollQuestion;
use Filagora\Forums\Models\PollVote;
use Filagora\Forums\Models\PollVoteAnswer;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Tests\Support\TestUser;

function privacyContent(TestUser $author): array
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::query()->firstOrCreate(['slug' => 'general'], ['forum_group_id' => $group->id, 'name' => 'General']);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'My thread', 'slug' => 't-'.uniqid()]);
    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'My words']);

    return [$thread, $post];
}

function ageLog(object $model, int $days): void
{
    $model->newQuery()->whereKey($model->getKey())->toBase()->update(['created_at' => now()->subDays($days)]);
}

it('contributes the forums\' data about a person to an export, and keeps what they wrote on erasure', function () {
    $author = TestUser::make('Author');
    [$thread, $post] = privacyContent($author);
    [$otherThread] = privacyContent(TestUser::make('Other'));
    $poll = Poll::create(['thread_id' => $thread->id, 'status' => 'open']);
    $question = PollQuestion::create(['poll_id' => $poll->id, 'prompt' => 'Q?']);
    $option = PollOption::create(['poll_question_id' => $question->id, 'label' => 'Yes']);
    $vote = PollVote::create(['poll_id' => $poll->id, 'user_id' => $author->id]);
    PollVoteAnswer::create(['poll_vote_id' => $vote->id, 'poll_question_id' => $question->id, 'poll_option_id' => $option->id]);
    PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => $author->id, 'reason' => 'Spam?']);

    $contributor = app(PrivacyContributorRegistry::class)->all()['forums'];
    $export = $contributor->export($author);

    expect(array_keys($export))->toBe(['threads', 'posts', 'poll_votes', 'post_reports'])
        ->and(array_column($export['threads'], 'title'))->toBe(['My thread'])
        ->and(array_column($export['posts'], 'body'))->toBe(['My words'])
        ->and($export['poll_votes'])->toBe([['poll_id' => $poll->id, 'voted_at' => $vote->created_at->toIso8601String(), 'option_ids' => [$option->id]]])
        ->and($export['post_reports'][0]['reason'])->toBe('Spam?')
        ->and($export['threads'][0])->not->toHaveKey('author_id');

    $contributor->erase($author);

    expect(Thread::count())->toBe(2)->and(Post::count())->toBe(2)->and($otherThread->exists)->toBeTrue();
});

it('registers the moderation log and dismissed report retention rules', function () {
    expect(array_keys(app(RetentionRuleRegistry::class)->all()))->toContain('forums.thread_moderation_logs', 'forums.post_moderation_logs', 'forums.dismissed_reports');
});

it('prunes moderation logs past their window, and only those', function () {
    $moderator = TestUser::make('Mod');
    [$thread, $post] = privacyContent($moderator);
    $oldThreadLog = ThreadModerationLog::create(['thread_id' => $thread->id, 'moderator_id' => $moderator->id, 'action' => 'lock']);
    $newThreadLog = ThreadModerationLog::create(['thread_id' => $thread->id, 'moderator_id' => $moderator->id, 'action' => 'unlock']);
    $oldPostLog = PostModerationLog::create(['thread_id' => $thread->id, 'post_id' => $post->id, 'moderator_id' => $moderator->id, 'action' => 'delete']);
    ageLog($oldThreadLog, 1100);
    ageLog($oldPostLog, 1100);
    ageLog($newThreadLog, 1000);

    $rules = app(RetentionRuleRegistry::class)->all();

    expect($rules['forums.thread_moderation_logs']->prune(true))->toBe(1)
        ->and(ThreadModerationLog::count())->toBe(2)
        ->and($rules['forums.thread_moderation_logs']->prune(false))->toBe(1)
        ->and($rules['forums.post_moderation_logs']->prune(false))->toBe(1)
        ->and(ThreadModerationLog::pluck('id')->all())->toBe([$newThreadLog->id])
        ->and(PostModerationLog::count())->toBe(0);

    config(['filagora-forums.retention.thread_moderation_logs_days' => 0]);
    ageLog($newThreadLog, 5000);
    expect($rules['forums.thread_moderation_logs']->prune(false))->toBe(0)->and(ThreadModerationLog::count())->toBe(1);
});

it('prunes dismissed reports after their window, by review date, falling back to the date made', function () {
    $author = TestUser::make('Author');
    [$thread, $post] = privacyContent($author);
    $report = fn (string $status, ?int $reviewedDaysAgo, int $madeDaysAgo): PostReport => tap(
        PostReport::create(['post_id' => $post->id, 'thread_id' => $thread->id, 'reporter_id' => TestUser::make('R')->id, 'reason' => 'x', 'status' => $status]),
        fn (PostReport $r) => $r->newQuery()->whereKey($r->id)->toBase()->update(['reviewed_at' => $reviewedDaysAgo === null ? null : now()->subDays($reviewedDaysAgo), 'created_at' => now()->subDays($madeDaysAgo)]),
    );

    $oldDismissed = $report('dismissed', 100, 200);
    $freshDismissed = $report('dismissed', 10, 200);
    $neverReviewedOld = $report('dismissed', null, 100);
    $neverReviewedNew = $report('dismissed', null, 10);
    $oldOpen = $report('open', null, 500);
    $oldReviewed = $report('reviewed', 500, 500);

    $rule = app(RetentionRuleRegistry::class)->all()['forums.dismissed_reports'];

    expect($rule->prune(true))->toBe(2)
        ->and($rule->prune(false))->toBe(2)
        ->and(PostReport::pluck('id')->all())->toEqualCanonicalizing([$freshDismissed->id, $neverReviewedNew->id, $oldOpen->id, $oldReviewed->id]);

    config(['filagora-forums.retention.dismissed_reports_days' => 0]);
    expect($rule->prune(false))->toBe(0);
});
