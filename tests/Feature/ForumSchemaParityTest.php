<?php

declare(strict_types=1);

use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;

/*
 * The package's tables must match the forum they were extracted from, so an existing site can
 * adopt them as they are. The shipped snapshot is that shape (checked against the real table
 * definitions, differing only by the extras a profile package declares); these tests keep the
 * migration in step with the snapshot and pin the details that matter most.
 */

const FORUM_TABLES = ['forum_groups', 'forums', 'threads', 'posts', 'post_reports', 'post_moderation_logs', 'thread_moderation_logs', 'site_notifications', 'user_site_notification_dismissals', 'polls', 'poll_questions', 'poll_options', 'poll_votes', 'poll_vote_answers', 'reactions', 'thread_subscriptions', 'thread_subscription_digest_items', 'forum_reads', 'thread_reads'];

it('ships a snapshot of the nineteen forum tables that matches the migration, column order included', function () {
    $snapshot = app(SchemaRegistry::class)->get('forums');

    expect($snapshot)->not->toBeNull()
        ->and(array_keys($snapshot->tables))->toBe(FORUM_TABLES)
        ->and($snapshot->migrationNames())->toBe(['2026_10_11_000000_create_filagora_forum_tables', '2026_10_14_000000_create_filagora_announcement_tables', '2026_10_15_000000_create_filagora_poll_tables', '2026_10_15_100000_create_filagora_reaction_tables', '2026_10_15_200000_create_filagora_subscription_tables', '2026_10_16_000000_create_filagora_read_tracking_tables']);

    foreach ($snapshot->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('generates threads.last_activity_at in the database from last_post_at and created_at', function () {
    $shape = TableShape::inspect('threads');

    expect($shape->columns['last_activity_at']['generation'])->toBe([
        'type' => 'virtual',
        'expression' => 'case when last_post_at is null or last_post_at < created_at then created_at else last_post_at end',
    ])->and($shape->columns['last_activity_at']['type'])->toBe('datetime');
});

it('has the FULLTEXT indexes on forum names, thread titles and post bodies', function () {
    foreach (['forums' => ['forums_name_fulltext', 'name'], 'threads' => ['threads_title_fulltext', 'title'], 'posts' => ['posts_body_fulltext', 'body']] as $table => [$index, $column]) {
        $shape = TableShape::inspect($table);

        expect($shape->indexes[$index]['type'])->toBe('fulltext')
            ->and($shape->indexes[$index]['columns'])->toBe([$column]);
    }
});

it('keeps the tuned listing indexes on threads and posts', function () {
    $threads = TableShape::inspect('threads')->indexes;
    $posts = TableShape::inspect('posts')->indexes;

    expect($threads['threads_forum_listing_idx']['columns'])->toBe(['forum_id', 'is_pinned', 'last_post_at', 'created_at'])
        ->and($threads['threads_forum_author_listing_idx']['columns'])->toBe(['forum_id', 'author_id', 'is_pinned', 'last_post_at', 'created_at'])
        ->and($threads['threads_forum_moderation_lastpost_idx']['columns'])->toBe(['forum_id', 'moderation_status', 'last_post_at'])
        ->and($threads['threads_global_listing_idx']['columns'])->toBe(['is_pinned', 'last_post_at', 'created_at'])
        ->and($threads['threads_slug_unique']['unique'])->toBeTrue()
        ->and($posts['posts_thread_visible_order_idx']['columns'])->toBe(['thread_id', 'is_deleted', 'created_at', 'id'])
        ->and($posts['posts_thread_visible_count_idx']['columns'])->toBe(['thread_id', 'is_deleted', 'moderation_status', 'id'])
        ->and($posts['posts_author_visible_count_idx']['columns'])->toBe(['author_id', 'is_deleted', 'moderation_status'])
        ->and(count($threads))->toBe(17)
        ->and(count($posts))->toBe(16);
});

it('reuses the composite indexes for foreign keys instead of adding separate ones', function () {
    $threads = TableShape::inspect('threads')->indexes;
    $forums = TableShape::inspect('forums')->indexes;

    // forum_id and author_id lead composite indexes, so MariaDB adds no *_foreign index for them.
    expect($threads)->not->toHaveKeys(['threads_forum_id_foreign', 'threads_author_id_foreign'])
        ->and($forums)->not->toHaveKey('forums_forum_group_id_foreign')
        ->and($threads)->toHaveKeys(['threads_last_post_author_id_foreign', 'threads_approved_by_id_foreign', 'threads_rejected_by_id_foreign']);
});

it('has the foreign keys and delete rules the forum relies on', function () {
    $rules = fn (string $table): array => collect(TableShape::inspect($table)->foreignKeys)
        ->mapWithKeys(fn (array $key): array => [$key['columns'][0] => $key['on'].' '.$key['on_delete']])
        ->all();

    expect($rules('forums'))->toBe(['forum_group_id' => 'forum_groups cascade'])
        ->and($rules('threads'))->toBe([
            'approved_by_id' => '{users} set null',
            'author_id' => '{users} cascade',
            'forum_id' => 'forums cascade',
            'last_post_author_id' => '{users} set null',
            'rejected_by_id' => '{users} set null',
        ])
        ->and($rules('posts'))->toBe([
            'approved_by_id' => '{users} set null',
            'author_id' => '{users} cascade',
            'deleted_by_id' => '{users} set null',
            'rejected_by_id' => '{users} set null',
            'thread_id' => 'threads cascade',
        ])
        ->and($rules('post_reports'))->toBe([
            'post_id' => 'posts cascade',
            'reporter_id' => '{users} cascade',
            'reviewed_by_id' => '{users} set null',
            'thread_id' => 'threads cascade',
        ])
        ->and($rules('post_moderation_logs'))->toBe(['moderator_id' => '{users} set null', 'post_id' => 'posts cascade', 'thread_id' => 'threads cascade'])
        ->and($rules('thread_moderation_logs'))->toBe(['moderator_id' => '{users} set null', 'thread_id' => 'threads cascade']);
});

it('leaves posts.edited_by_id without a foreign key or an index, as in the existing schema', function () {
    $posts = TableShape::inspect('posts');

    expect($posts->columns['edited_by_id']['nullable'])->toBeTrue()
        ->and(collect($posts->foreignKeys)->pluck('columns')->flatten()->all())->not->toContain('edited_by_id')
        ->and(collect($posts->indexes)->pluck('columns')->flatten()->all())->not->toContain('edited_by_id');
});

it('stores post bodies as mediumtext and keeps the stored defaults', function () {
    $posts = TableShape::inspect('posts')->columns;
    $forums = TableShape::inspect('forums')->columns;

    expect($posts['body']['type'])->toBe('mediumtext')
        ->and($posts['content_format'])->toMatchArray(['type' => 'varchar(20)', 'default' => 'markdown'])
        ->and($posts['moderation_status']['default'])->toBe('approved')
        ->and($forums['visibility']['default'])->toBe('public')
        ->and($forums['posting_policy']['default'])->toBe('registered')
        ->and(DB::table(Tables::name('forum_groups'))->count())->toBe(0);
});
