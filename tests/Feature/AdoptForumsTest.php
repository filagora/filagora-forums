<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Support\Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const FORUM_MIGRATION = '2026_10_11_000000_create_filagora_forum_tables';
const ANNOUNCEMENT_MIGRATION = '2026_10_14_000000_create_filagora_announcement_tables';

function packageMigration(string $name): object
{
    return require __DIR__.'/../../database/migrations/'.$name.'.php';
}

/**
 * Build the tables the way an existing site has them: no prefix, and the migration not recorded.
 */
function existingForumTables(): void
{
    // The announcement tables' constraint names do not carry the prefix, so the prefixed set has
    // to go before an unprefixed one can exist beside it. afterEach() puts it back.
    packageMigration(ANNOUNCEMENT_MIGRATION)->down();

    config(['filagora.table_prefix' => '']);

    foreach ([FORUM_MIGRATION, ANNOUNCEMENT_MIGRATION] as $name) {
        $migration = packageMigration($name);
        $migration->down();
        $migration->up();
    }

    DB::table('migrations')->whereIn('migration', [FORUM_MIGRATION, ANNOUNCEMENT_MIGRATION])->delete();
}

/**
 * Columns, indexes and a foreign key-free extra a site might add: legacy import ids with
 * unique keys, and a feature flag. This is the shape a profile package declares.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function siteExtras(): array
{
    $bigint = ['type' => 'bigint unsigned', 'nullable' => true, 'default' => null, 'collation' => null, 'auto_increment' => false, 'generation' => null];
    $unique = fn (string $column): array => ['columns' => [$column], 'unique' => true, 'primary' => false, 'type' => 'btree'];

    return [
        'forums' => [
            'columns' => [
                'legacy_forum_id' => $bigint,
                'is_special' => ['type' => 'tinyint(1)', 'nullable' => false, 'default' => '0', 'collation' => null, 'auto_increment' => false, 'generation' => null],
            ],
            'indexes' => ['forums_legacy_forum_id_unique' => $unique('legacy_forum_id')],
        ],
        'threads' => [
            'columns' => ['legacy_thread_id' => $bigint],
            'indexes' => ['threads_legacy_thread_id_unique' => $unique('legacy_thread_id')],
        ],
        'posts' => [
            'columns' => ['legacy_post_id' => $bigint],
            'indexes' => ['posts_legacy_post_id_unique' => $unique('legacy_post_id')],
        ],
    ];
}

function addSiteExtrasToTables(): void
{
    Schema::table('forums', function (Blueprint $table): void {
        $table->unsignedBigInteger('legacy_forum_id')->nullable()->unique('forums_legacy_forum_id_unique');
        $table->boolean('is_special')->default(false);
    });
    Schema::table('threads', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_thread_id')->nullable()->unique('threads_legacy_thread_id_unique'));
    Schema::table('posts', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_post_id')->nullable()->unique('posts_legacy_post_id_unique'));
}

function forumsSchema()
{
    return app(SchemaRegistry::class)->get('forums');
}

afterEach(function () {
    foreach (array_reverse(FORUM_TABLES) as $table) {
        Schema::dropIfExists($table);
    }

    config(['filagora.table_prefix' => 'filagora_']);

    if (! Schema::hasTable(Tables::name('site_notifications'))) {
        packageMigration(ANNOUNCEMENT_MIGRATION)->up();
    }
});

it('adopts the forum tables of an existing site and records the migration', function () {
    existingForumTables();

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([FORUM_MIGRATION, ANNOUNCEMENT_MIGRATION]);

    $this->artisan('filagora:adopt', ['--package' => 'forums'])->expectsOutputToContain('Recorded 2 migration(s) as run.')->assertExitCode(0);

    expect(DB::table('migrations')->whereIn('migration', [FORUM_MIGRATION, ANNOUNCEMENT_MIGRATION])->count())->toBe(2);
});

it('refuses a site whose tables carry columns nobody declared, naming each one', function () {
    existingForumTables();
    addSiteExtrasToTables();

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toEqualCanonicalizing([
            'forums: column legacy_forum_id is not expected',
            'forums: column is_special is not expected',
            'forums: index forums_legacy_forum_id_unique is not expected',
            'threads: column legacy_thread_id is not expected',
            'threads: index threads_legacy_thread_id_unique is not expected',
            'posts: column legacy_post_id is not expected',
            'posts: index posts_legacy_post_id_unique is not expected',
        ]);
});

it('adopts that site once a profile package declares its extras, exactly', function () {
    existingForumTables();
    addSiteExtrasToTables();
    app(SchemaRegistry::class)->extend(new SchemaExtension('site', 'forums', siteExtras()));

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences));
});

it('still catches a declared extra that differs from the site\'s table', function () {
    existingForumTables();
    addSiteExtrasToTables();
    Schema::table('threads', fn (Blueprint $table) => $table->string('legacy_thread_id', 20)->nullable()->change());
    app(SchemaRegistry::class)->extend(new SchemaExtension('site', 'forums', siteExtras()));

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('threads: column legacy_thread_id type is varchar(20), expected bigint unsigned');
});

it('catches a site whose generated column or FULLTEXT index was lost', function () {
    existingForumTables();
    Schema::table('threads', function (Blueprint $table): void {
        $table->dropFullText('threads_title_fulltext');
        $table->index('title', 'threads_title_fulltext');
    });

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toBe(['threads: index threads_title_fulltext type is btree, expected fulltext']);
});

it('refuses a site that has only some of the forum tables', function () {
    existingForumTables();
    Schema::drop('thread_moderation_logs');

    $plan = app(Adopter::class)->planFor(forumsSchema());

    expect($plan->status)->toBe(AdoptionStatus::Partial)
        ->and($plan->differences)->toBe(['thread_moderation_logs: table is missing while other tables of this package exist']);
});

it('is already adopted on a site that ran the package migration', function () {
    expect(app(Adopter::class)->planFor(forumsSchema())->status)->toBe(AdoptionStatus::AlreadyAdopted)
        ->and(Tables::name('forums'))->toBe('filagora_forums');
});
