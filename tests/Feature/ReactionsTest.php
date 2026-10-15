<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Enums\ReactionRefusal;
use Filagora\Forums\Exceptions\ReactionRefused;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Reaction;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Services\Reactions;
use Filagora\Forums\Tests\Support\TestUser;

function reactionsService(): Reactions
{
    app()->forgetInstance(ForumAccess::class);
    app()->forgetInstance(Reactions::class);

    return app(Reactions::class);
}

function reactablePost(array $post = [], array $forum = []): Post
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(array_merge(['forum_group_id' => $group->id, 'name' => 'F'.uniqid(), 'slug' => 'f-'.uniqid()], $forum));
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => TestUser::make('Author')->id, 'title' => 'T', 'slug' => 't-'.uniqid()]);

    return Post::create(array_merge(['thread_id' => $thread->id, 'author_id' => $thread->author_id, 'body' => 'Hello'], $post))->refresh();
}

function reactionRefusal(callable $call): ?ReactionRefusal
{
    try {
        $call();
    } catch (ReactionRefused $e) {
        return $e->reason;
    }

    return null;
}

it('adds a reaction, and takes it back when made again', function () {
    $post = reactablePost();
    $user = TestUser::make('Fan');

    expect(reactionsService()->toggle($user, $post))->toBeTrue()
        ->and(Reaction::where(['post_id' => $post->id, 'user_id' => $user->id, 'type' => 'like'])->count())->toBe(1)
        ->and(reactionsService()->toggle($user, $post))->toBeFalse()
        ->and(Reaction::count())->toBe(0)
        ->and(reactionsService()->toggle($user, $post))->toBeTrue();
});

it('keeps one person\'s reaction apart from another\'s, and one post\'s from another\'s', function () {
    $post = reactablePost();
    $other = reactablePost();
    $a = TestUser::make('A');
    $b = TestUser::make('B');

    reactionsService()->toggle($a, $post);
    reactionsService()->toggle($b, $post);
    reactionsService()->toggle($a, $other);
    reactionsService()->toggle($a, $post);

    expect(Reaction::where('post_id', $post->id)->pluck('user_id')->all())->toBe([$b->id])
        ->and(Reaction::where('post_id', $other->id)->count())->toBe(1);
});

it('offers only the configured kinds, like by default', function () {
    $post = reactablePost();
    $user = TestUser::make('Fan');

    expect(reactionsService()->types())->toBe(['like'])
        ->and(reactionRefusal(fn () => reactionsService()->toggle($user, $post, 'love')))->toBe(ReactionRefusal::UNKNOWN_TYPE);

    config(['filagora-forums.reactions.types' => ['like', 'love', 'like', '']]);

    expect(reactionsService()->types())->toBe(['like', 'love'])
        ->and(reactionsService()->toggle($user, $post, 'love'))->toBeTrue()
        ->and(reactionsService()->toggle($user, $post, 'like'))->toBeTrue()
        ->and(Reaction::where('post_id', $post->id)->orderBy('id')->pluck('type')->all())->toBe(['love', 'like']);
});

it('takes no reaction to a post that is deleted or awaiting approval', function () {
    $user = TestUser::make('Fan');
    $deleted = reactablePost(['is_deleted' => true]);
    $pending = reactablePost(['moderation_status' => ContentModerationStatus::PENDING->value]);

    expect(reactionRefusal(fn () => reactionsService()->toggle($user, $deleted)))->toBe(ReactionRefusal::POST_DELETED)
        ->and(reactionRefusal(fn () => reactionsService()->toggle($user, $pending)))->toBe(ReactionRefusal::POST_NOT_APPROVED)
        ->and(Reaction::count())->toBe(0);
});

it('takes no reaction from someone who cannot see the thread', function () {
    $post = reactablePost([], ['visibility' => ForumVisibility::STAFF_ONLY->value]);

    expect(reactionRefusal(fn () => reactionsService()->toggle(TestUser::make('Member'), $post)))->toBe(ReactionRefusal::NOT_VISIBLE)
        ->and(Reaction::count())->toBe(0);
});

it('takes no reaction to a post in a thread the person cannot open, even in a forum they can see', function () {
    $pending = reactablePost();
    $pending->thread->forceFill(['moderation_status' => ContentModerationStatus::PENDING->value])->save();

    expect(reactionRefusal(fn () => reactionsService()->toggle(TestUser::make('Visitor'), $pending->refresh())))->toBe(ReactionRefusal::NOT_VISIBLE);
});

it('counts the reactions of many posts at once, by kind', function () {
    config(['filagora-forums.reactions.types' => ['like', 'love']]);
    $one = reactablePost();
    $two = reactablePost();
    $none = reactablePost();
    [$a, $b] = [TestUser::make('A'), TestUser::make('B')];
    reactionsService()->toggle($a, $one);
    reactionsService()->toggle($b, $one);
    reactionsService()->toggle($a, $one, 'love');
    reactionsService()->toggle($b, $two);

    $counts = reactionsService()->counts([$one->id, $two->id, $none->id, $one->id]);

    expect($counts)->toBe([$one->id => ['like' => 2, 'love' => 1], $two->id => ['like' => 1]])
        ->and(reactionsService()->counts([]))->toBe([]);
});

it('says which reactions a person has made to a list of posts', function () {
    config(['filagora-forums.reactions.types' => ['like', 'love']]);
    $one = reactablePost();
    $two = reactablePost();
    [$a, $b] = [TestUser::make('A'), TestUser::make('B')];
    reactionsService()->toggle($a, $one);
    reactionsService()->toggle($a, $one, 'love');
    reactionsService()->toggle($b, $two);

    expect(reactionsService()->madeBy($a, [$one->id, $two->id]))->toBe([$one->id => ['like', 'love']])
        ->and(reactionsService()->madeBy(null, [$one->id]))->toBe([])
        ->and(reactionsService()->madeBy($a, []))->toBe([]);
});

it('loses reactions with the post, and with the person', function () {
    $post = reactablePost();
    $user = TestUser::make('Fan');
    $other = TestUser::make('Other');
    reactionsService()->toggle($user, $post);
    reactionsService()->toggle($other, $post);

    $user->delete();
    expect(Reaction::pluck('user_id')->all())->toBe([$other->id]);

    $post->delete();
    expect(Reaction::count())->toBe(0);
});

it('exports a person\'s reactions', function () {
    $post = reactablePost();
    $user = TestUser::make('Fan');
    reactionsService()->toggle($user, $post);
    reactionsService()->toggle(TestUser::make('Someone else'), $post);

    $export = PrivacyContributorRegistry::class;
    $export = app($export)->all()['forums']->export($user);

    expect($export['reactions'])->toHaveCount(1)->and($export['reactions'][0]['post_id'])->toBe($post->id)->and($export['reactions'][0]['type'])->toBe('like');
});
