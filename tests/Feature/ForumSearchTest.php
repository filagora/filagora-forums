<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Enums\ForumVisibility;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Search\ForumSearch;
use Filagora\Forums\Search\ForumSearchCriteria;
use Filagora\Forums\Search\ThreadDatabaseSearch;
use Filagora\Forums\Search\ThreadSearchIndex;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Tests\Support\TestUser;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Search;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Illuminate\Support\Facades\DB;

/*
 * Every scenario runs on the array engine (documents in memory, filters evaluated in PHP) and on
 * the database engine (the same query answered from the tables), and must give the same answer.
 * The database path uses LIKE here: FULLTEXT cannot see rows inside a test transaction (it has
 * its own test, which commits).
 */

function searchEngineOn(string $engine): ArrayEngine
{
    $array = new ArrayEngine;
    app(SearchEngines::class)->extend('array', fn () => $array);
    config(['filagora-search.engine' => $engine, 'filagora-forums.search.fulltext' => false]);
    app()->forgetInstance(ForumSearch::class);
    app()->forgetInstance(ForumAccess::class);
    $array->syncSettings(app(ThreadSearchIndex::class)->definition());

    return $array;
}

function searchForum(string $name = 'General', ForumVisibility $visibility = ForumVisibility::PUBLIC, bool $active = true): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(['forum_group_id' => $group->id, 'name' => $name, 'slug' => 'f-'.uniqid(), 'visibility' => $visibility, 'is_active' => $active]);
}

function searchThread(Forum $forum, TestUser $author, string $title, array $extra = [], ?string $firstPost = null): Thread
{
    $thread = Thread::create(array_merge(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => $title, 'slug' => 't-'.uniqid()], $extra));

    if ($firstPost !== null) {
        Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => $firstPost]);
    }

    return $thread->refresh();
}

/**
 * @return list<int>
 */
function searchFound(?TestUser $viewer, ForumSearchCriteria $criteria, int $page = 1): array
{
    return app(ForumSearch::class)->threads($criteria, $viewer, $page)->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
}

function searchSortedIds(array $threads): array
{
    $ids = array_map(fn (Thread $thread) => $thread->id, $threads);
    sort($ids);

    return $ids;
}

dataset('engines', ['array', 'database']);

it('finds threads by a word in the title or in an approved post', function (string $engine) {
    searchEngineOn($engine);
    $author = TestUser::make('Author');
    $forum = searchForum();
    $byTitle = searchThread($forum, $author, 'PayPal setup');
    $byPost = searchThread($forum, $author, 'Other', firstPost: 'Works with PayPal checkout');
    searchThread($forum, $author, 'Unrelated', firstPost: 'Nothing here');

    expect(searchFound(null, new ForumSearchCriteria('paypal')))->toEqualCanonicalizing([$byTitle->id, $byPost->id])
        ->and(searchFound(null, new ForumSearchCriteria('zzzmissing')))->toBe([])
        ->and(searchFound(null, new ForumSearchCriteria('')))->toHaveCount(3);
})->with('engines');

it('does not search the text of deleted or unapproved posts', function (string $engine) {
    searchEngineOn($engine);
    $author = TestUser::make('Author');
    $thread = searchThread(searchForum(), $author, 'Quiet thread');
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'secretword deleted', 'is_deleted' => true]);
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'secretword pending', 'moderation_status' => ContentModerationStatus::PENDING]);

    expect(searchFound(null, new ForumSearchCriteria('secretword')))->toBe([]);
})->with('engines');

it('shows each viewer only the forums they may see', function (string $engine) {
    searchEngineOn($engine);
    $author = TestUser::make('Author');
    $member = TestUser::make('Member');
    $public = searchThread(searchForum('Public'), $author, 'Shared topic');
    $registered = searchThread(searchForum('Members', ForumVisibility::REGISTERED), $author, 'Shared topic');
    searchThread(searchForum('Staff', ForumVisibility::STAFF_ONLY), $author, 'Shared topic');
    $mineForum = searchForum('Private', ForumVisibility::STAFF_AUTHOR_ONLY);
    $own = searchThread($mineForum, $member, 'Shared topic');
    $authorOwn = searchThread($mineForum, $author, 'Shared topic');
    $criteria = new ForumSearchCriteria('shared');

    expect(searchFound(null, $criteria))->toBe([$public->id])
        ->and(searchFound($author, $criteria))->toEqualCanonicalizing([$public->id, $registered->id, $authorOwn->id])
        ->and(searchFound($member, $criteria))->toEqualCanonicalizing([$public->id, $registered->id, $own->id]);
})->with('engines');

it('leaves out threads that are not approved and forums that are not active', function (string $engine) {
    searchEngineOn($engine);
    $author = TestUser::make('Author');
    $good = searchThread(searchForum(), $author, 'Visible topic');
    searchThread(searchForum('Off', active: false), $author, 'Visible topic');
    searchThread(searchForum('Other'), $author, 'Visible topic', ['moderation_status' => ContentModerationStatus::PENDING]);

    expect(searchFound(null, new ForumSearchCriteria('visible')))->toBe([$good->id]);
})->with('engines');

it('filters by forum, author, minimum replies and recent activity', function (string $engine) {
    searchEngineOn($engine);
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $one = searchForum('One');
    $two = searchForum('Two');
    $old = searchThread($one, $alice, 'Topic old', ['last_post_at' => now()->subDays(40)->setTime(12, 0)]);
    DB::table($old->getTable())->where('id', $old->id)->update(['created_at' => now()->subDays(41)->setTime(12, 0)]);
    app(Indexer::class)->sync(app(ThreadSearchIndex::class), $old->refresh());
    $busy = searchThread($one, $alice, 'Topic busy', ['reply_count' => 5, 'last_post_at' => now()->subDays(2)->setTime(12, 0)]);
    $bobs = searchThread($two, $bob, 'Topic bobs', ['last_post_at' => now()->subDay()->setTime(12, 0)]);
    $criteria = fn (array $with) => new ForumSearchCriteria(...['query' => 'topic', ...$with]);

    expect(searchFound(null, $criteria(['forumId' => $two->id])))->toBe([$bobs->id])
        ->and(searchFound(null, $criteria(['authorId' => $alice->id])))->toEqualCanonicalizing([$old->id, $busy->id])
        ->and(searchFound(null, $criteria(['minReplies' => 3])))->toBe([$busy->id])
        ->and(searchFound(null, $criteria(['since' => '7d'])))->toEqualCanonicalizing([$busy->id, $bobs->id])
        ->and(searchFound(null, $criteria(['since' => '1y'])))->toHaveCount(3);
})->with('engines');

it('finds threads a person posted in, or only those they started', function (string $engine) {
    searchEngineOn($engine);
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $forum = searchForum();
    $started = searchThread($forum, $alice, 'Topic one');
    $joined = searchThread($forum, $bob, 'Topic two');
    Post::create(['thread_id' => $joined->id, 'author_id' => $alice->id, 'body' => 'Me too']);
    $other = searchThread($forum, $bob, 'Topic three');
    $criteria = fn (array $with) => new ForumSearchCriteria(...['query' => 'topic', ...$with]);

    expect(searchFound(null, $criteria(['authorId' => $alice->id, 'authorMode' => 'started'])))->toBe([$started->id])
        ->and(searchFound(null, $criteria(['authorId' => $alice->id, 'authorMode' => 'posted'])))->toEqualCanonicalizing([$started->id, $joined->id])
        ->and(searchFound(null, $criteria(['mineUserId' => $alice->id])))->toEqualCanonicalizing([$started->id, $joined->id])
        ->and(searchFound(null, $criteria(['mineUserId' => $bob->id])))->toEqualCanonicalizing([$joined->id, $other->id]);
})->with('engines');

it('sorts by last post, newest or replies, and pages the results', function (string $engine) {
    searchEngineOn($engine);
    $author = TestUser::make('Author');
    $forum = searchForum();
    $a = searchThread($forum, $author, 'Topic a', ['reply_count' => 1, 'last_post_at' => now()->subDays(3)->setTime(12, 0)]);
    $b = searchThread($forum, $author, 'Topic b', ['reply_count' => 9, 'last_post_at' => now()->subDay()->setTime(12, 0)]);
    $c = searchThread($forum, $author, 'Topic c', ['reply_count' => 4, 'last_post_at' => now()->subDays(2)->setTime(12, 0)]);
    DB::table($a->getTable())->where('id', $a->id)->update(['created_at' => now()->subDays(10)->setTime(12, 0)]);
    DB::table($b->getTable())->where('id', $b->id)->update(['created_at' => now()->subDays(12)->setTime(12, 0)]);
    DB::table($c->getTable())->where('id', $c->id)->update(['created_at' => now()->subDays(11)->setTime(12, 0)]);
    app(Indexer::class)->reindex(app(ThreadSearchIndex::class));
    $criteria = fn (string $sort) => new ForumSearchCriteria('topic', sort: $sort);

    expect(searchFound(null, $criteria('last_post')))->toBe([$b->id, $c->id, $a->id])
        ->and(searchFound(null, $criteria('newest')))->toBe([$a->id, $c->id, $b->id])
        ->and(searchFound(null, $criteria('replies')))->toBe([$b->id, $c->id, $a->id]);

    $page = app(ForumSearch::class)->threads($criteria('last_post'), null, 1);
    expect($page->total())->toBe(3)->and($page->perPage())->toBe(20)
        ->and(searchFound(null, $criteria('last_post'), 2))->toBe([]);
})->with('engines');

it('drops a hit whose row is no longer visible, because the index can be stale', function () {
    $engine = searchEngineOn('array');
    $author = TestUser::make('Author');
    $thread = searchThread(searchForum(), $author, 'Stale topic');
    $gone = searchThread(searchForum('Other'), $author, 'Stale topic');

    expect(searchFound(null, new ForumSearchCriteria('stale')))->toEqualCanonicalizing([$thread->id, $gone->id]);

    DB::table($thread->getTable())->where('id', $thread->id)->update(['moderation_status' => 'rejected']);
    DB::table($gone->forum()->getRelated()->getTable())->where('id', $gone->forum_id)->update(['is_active' => false]);

    $page = app(ForumSearch::class)->threads(new ForumSearchCriteria('stale'), null);
    expect($page->getCollection())->toHaveCount(0)->and($page->total())->toBe(1)
        ->and($engine->stats(app(ThreadSearchIndex::class)->definition())?->documents)->toBe(2);
});

it('returns nothing to a viewer who can see no forum', function () {
    searchEngineOn('array');
    $author = TestUser::make('Author');
    searchThread(searchForum('Staff', ForumVisibility::STAFF_ONLY), $author, 'Hidden topic');

    expect(searchFound(null, new ForumSearchCriteria('hidden')))->toBe([])
        ->and(app(ForumSearch::class)->threads(new ForumSearchCriteria('hidden'), null)->total())->toBe(0);
});

it('keeps thread documents current as posts and forums change', function () {
    $engine = searchEngineOn('array');
    $author = TestUser::make('Author');
    $forum = searchForum('Old name');
    $thread = searchThread($forum, $author, 'Plain title');
    $definition = app(ThreadSearchIndex::class)->definition();
    $ids = fn (string $text) => array_map('intval', $engine->search($definition, new SearchQuery($text))->ids());

    expect($ids('newword'))->toBe([]);

    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'a newword appears']);
    expect($ids('newword'))->toBe([$thread->id]);

    $post->update(['body' => 'now different']);
    expect($ids('newword'))->toBe([])->and($ids('different'))->toBe([$thread->id]);

    $post->delete();
    expect($ids('different'))->toBe([]);

    expect($ids('renamed'))->toBe([]);
    $forum->update(['name' => 'Renamed forum']);
    expect($ids('renamed'))->toBe([$thread->id]);

    $forum->update(['slug' => 'changed-slug']);
    $forum->update(['is_active' => false]);
    expect($ids('renamed'))->toBe([]);
});

it('describes a thread as a document, with the filter fields as their own group', function () {
    searchEngineOn('array');
    $author = TestUser::make('Author');
    $replier = TestUser::make('Replier');
    $forum = searchForum('Docs');
    $thread = searchThread($forum, $author, 'Doc title', ['reply_count' => 2, 'last_post_at' => now()->setTime(12, 0)], 'First <b>body</b>');
    Post::create(['thread_id' => $thread->id, 'author_id' => $replier->id, 'body' => 'Second']);
    $index = app(ThreadSearchIndex::class);
    $thread->refresh();

    $document = $index->toDocument($thread);
    $partial = $index->toPartialDocument($thread, 'filters');

    expect($document->id)->toBe($thread->id)
        ->and($document->fields['forum_name'])->toBe('Docs')
        ->and($document->fields['forum_is_active'])->toBeTrue()
        ->and($document->fields['post_search_text'])->toBe('First body Second')
        ->and($document->fields['post_author_ids'])->toBe([$author->id, $replier->id])
        ->and($document->fields['reply_count'])->toBe(2)
        ->and($partial->fields)->toBe([
            'post_author_ids' => [$author->id, $replier->id],
            'created_at_ts' => $thread->created_at->getTimestamp(),
            'last_post_at_ts' => $thread->last_post_at->getTimestamp(),
            'reply_count' => 2,
        ])
        ->and($index->groups())->toBe(['filters'])
        ->and(fn () => $index->toPartialDocument($thread, 'nope'))->toThrow(InvalidArgumentException::class);
});

it('caps the post text copied into a document', function () {
    searchEngineOn('array');
    config(['filagora-forums.search.post_text_limit' => 20]);
    $author = TestUser::make('Author');
    $thread = searchThread(searchForum(), $author, 'Long', firstPost: str_repeat('word ', 20));

    expect(mb_strlen(app(ThreadSearchIndex::class)->toDocument($thread)->fields['post_search_text']))->toBe(20);
});

it('knows which threads belong in the index and how many', function () {
    searchEngineOn('array');
    $author = TestUser::make('Author');
    $good = searchThread(searchForum(), $author, 'Good');
    $pending = searchThread(searchForum('B'), $author, 'Pending', ['moderation_status' => ContentModerationStatus::PENDING]);
    $inactive = searchThread(searchForum('Off', active: false), $author, 'Inactive');
    $index = app(ThreadSearchIndex::class);

    expect($index->shouldBeSearchable($good))->toBeTrue()
        ->and($index->shouldBeSearchable($pending))->toBeFalse()
        ->and($index->shouldBeSearchable($inactive->refresh()))->toBeFalse()
        ->and($index->shouldBeSearchable($good->forum))->toBeFalse()
        ->and($index->expectedCount())->toBe(1)
        ->and($index->query()->pluck('id')->all())->toBe([$good->id]);
});

it('lists unapproved threads for staff, and searches the posts of one thread', function () {
    searchEngineOn('array');
    $author = TestUser::make('Author');
    $forum = searchForum();
    $pending = searchThread($forum, $author, 'Awaiting review', ['moderation_status' => ContentModerationStatus::PENDING]);
    searchThread($forum, $author, 'Awaiting approved');
    $search = app(ForumSearch::class);

    expect($search->unapproved(new ForumSearchCriteria('awaiting'))->pluck('id')->all())->toBe([$pending->id])
        ->and($search->unapproved(new ForumSearchCriteria('awaiting', forumId: $forum->id, authorId: $author->id, minReplies: 0, since: '7d'))->pluck('id')->all())->toBe([$pending->id])
        ->and($search->unapproved(new ForumSearchCriteria('awaiting', minReplies: 1))->all())->toBe([]);

    $thread = searchThread($forum, $author, 'Posts here');
    $first = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'alpha needle']);
    Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'beta']);
    $third = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'gamma needle']);

    $matches = $search->posts(Post::query()->where('thread_id', $thread->id), new ForumSearchCriteria('needle'), approvedOnly: true, postsPerPage: 2);

    expect($matches->pluck('id')->all())->toBe([$first->id, $third->id])
        ->and($matches->getCollection()->pluck('search_post_number')->all())->toBe([1, 3])
        ->and($matches->getCollection()->pluck('search_page')->all())->toBe([1, 2]);

    $newest = $search->posts(Post::query()->where('thread_id', $thread->id), new ForumSearchCriteria('needle', postSort: 'newest', authorId: $author->id, since: '1d'), approvedOnly: true);
    expect($newest->pluck('id')->all())->toBe([$third->id, $first->id]);
});

it('understands every filter in the database fallback, and refuses a field it cannot filter on', function () {
    searchEngineOn('database');
    $author = TestUser::make('Author');
    $a = searchThread(searchForum(), $author, 'Alpha', ['reply_count' => 1]);
    $b = searchThread(searchForum('B'), $author, 'Beta', ['reply_count' => 5]);
    $database = app(ThreadDatabaseSearch::class);
    $ids = fn ($filter) => searchSortedIds(array_map(fn ($hit) => Thread::find($hit->id), $database->search(new SearchQuery(filter: $filter))->hits));

    expect($ids(new Not(new Equals('forum_id', $a->forum_id))))->toBe([$b->id])
        ->and($ids(new Any(new Equals('forum_id', $a->forum_id), new Range('reply_count', gte: 5))))->toBe([$a->id, $b->id])
        ->and($ids(new Any))->toBe([])
        ->and($ids(new All))->toBe([$a->id, $b->id])
        ->and($ids(new In('author_id', [])))->toBe([])
        ->and($ids(new Range('reply_count', lte: 1)))->toBe([$a->id])
        ->and($ids(new Equals('forum_is_active', true)))->toBe([$a->id, $b->id])
        ->and(fn () => $ids(new Equals('title', 'x')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ids(new Range('title', gte: 1)))->toThrow(InvalidArgumentException::class)
        ->and(app(Search::class)->search(ThreadSearchIndex::NAME, new SearchQuery('alpha'))->ids())->toBe([$a->id]);
});

it('uses the FULLTEXT indexes on MariaDB', function () {
    config(['filagora-search.engine' => 'database', 'filagora-forums.search.fulltext' => true]);
    app()->forgetInstance(ForumSearch::class);
    $connection = DB::connection();
    $connection->commit();

    try {
        $author = TestUser::make('Author');
        $thread = searchThread(searchForum('Full'), $author, 'Zebra crossing guide', firstPost: 'Some words about marmalade');
        $other = searchThread(searchForum('Full2'), $author, 'Something else entirely');

        expect(searchFound(null, new ForumSearchCriteria('zebra')))->toBe([$thread->id])
            ->and(searchFound(null, new ForumSearchCriteria('marmalade')))->toBe([$thread->id])
            ->and(searchFound(null, new ForumSearchCriteria('"zebra crossing"')))->toBe([$thread->id])
            ->and(searchFound(null, new ForumSearchCriteria('nonexistentword')))->toBe([])
            ->and($other->id)->not->toBe($thread->id);
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['posts', 'threads', 'forums', 'forum_groups'] as $table) {
            DB::table(Tables::name($table))->delete();
        }
        DB::table('users')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $connection->beginTransaction();
    }
});

it('has working criteria defaults', function () {
    $criteria = new ForumSearchCriteria;

    expect($criteria->sinceTimestamp())->toBeNull()
        ->and((new ForumSearchCriteria(since: 'bogus'))->sinceTimestamp())->toBeNull()
        ->and((new ForumSearchCriteria(since: '1d'))->sinceTimestamp())->toBeGreaterThan(now()->subDays(2)->getTimestamp())
        ->and((new ForumSearchCriteria(sort: 'relevance'))->effectiveSort())->toBe('last_post')
        ->and((new ForumSearchCriteria('x', sort: 'relevance'))->effectiveSort())->toBe('relevance');
});

it('does nothing for thread search documents with the database engine', function () {
    searchEngineOn('database');
    $author = TestUser::make('Author');

    searchThread(searchForum(), $author, 'Quiet', firstPost: 'text');

    expect(app(Indexer::class)->isActive())->toBeFalse()
        ->and(app(Search::class)->search(ThreadSearchIndex::NAME, new SearchQuery('quiet'))->total)->toBe(1)
        ->and((new Document(1, []))->fields)->toBe([]);
});
