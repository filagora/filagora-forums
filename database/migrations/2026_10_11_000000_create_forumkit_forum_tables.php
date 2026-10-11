<?php

declare(strict_types=1);

use ForumKit\Core\Support\Tables;
use ForumKit\Core\Support\UserKey;
use ForumKit\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * These tables match the shape of the forum the package was extracted from, index for index:
 * the composite indexes are tuned for a forum with hundreds of thousands of threads and over a
 * million posts. Where a foreign key column leads one of those composite indexes, the index is
 * declared first so the database reuses it instead of adding a separate one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('forum_groups'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'forum_groups_active_sort_idx');
        });

        Schema::create(Tables::name('forums'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('forum_group_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('visibility')->default('public')->index();
            $table->string('required_entitlement_scope')->nullable();
            $table->string('reply_entitlement_scope')->nullable();
            $table->string('posting_policy')->default('registered')->index();
            $table->boolean('is_read_only')->default(false)->index();
            $table->boolean('requires_thread_approval')->default(false);
            $table->boolean('requires_reply_approval')->default(false);
            $table->boolean('show_when_empty')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('thread_count')->default(0);
            $table->unsignedInteger('post_count')->default(0);
            $table->unsignedBigInteger('last_post_id')->nullable()->index();
            $table->unsignedBigInteger('last_post_thread_id')->nullable()->index();
            $table->unsignedBigInteger('last_post_author_id')->nullable()->index();
            $table->timestamp('last_post_at')->nullable()->index();
            $table->timestamps();

            $table->index(['forum_group_id', 'is_active', 'sort_order'], 'forums_group_active_sort_idx');
            $table->fullText('name');
            $table->foreign('forum_group_id')->references('id')->on(Tables::name('forum_groups'))->cascadeOnDelete();
        });

        Schema::create(Tables::name('threads'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('forum_id');
            UserKey::column($table, 'author_id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('moderation_status')->default('approved')->index();
            $table->boolean('is_pinned')->default(false)->index();
            $table->boolean('is_locked')->default(false)->index();
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            UserKey::column($table, 'last_post_author_id')->nullable();
            $table->timestamp('last_post_at')->nullable()->index();
            UserKey::column($table, 'approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            UserKey::column($table, 'rejected_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
            $table->dateTime('last_activity_at')->virtualAs(
                'case when `last_post_at` is null or `last_post_at` < `created_at` then `created_at` else `last_post_at` end'
            );

            $table->index(['forum_id', 'is_pinned', 'last_post_at', 'created_at'], 'threads_forum_listing_idx');
            $table->index(['forum_id', 'author_id', 'is_pinned', 'last_post_at', 'created_at'], 'threads_forum_author_listing_idx');
            $table->index(['author_id', 'forum_id'], 'threads_author_forum_idx');
            $table->index(['forum_id', 'moderation_status', 'id'], 'threads_forum_moderation_id_idx');
            $table->index(['forum_id', 'moderation_status', 'last_post_at'], 'threads_forum_moderation_lastpost_idx');
            $table->index(['is_pinned', 'last_post_at', 'created_at'], 'threads_global_listing_idx');
            $table->index('last_activity_at', 'threads_last_activity_idx');
            $table->fullText('title');

            $table->foreign('forum_id')->references('id')->on(Tables::name('forums'))->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('last_post_author_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('approved_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('rejected_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('posts'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            UserKey::column($table, 'author_id');
            $table->mediumText('body');
            $table->string('content_format', 20)->default('markdown');
            $table->string('moderation_status')->default('approved')->index();
            $table->text('signature')->nullable();
            $table->string('signature_format', 20)->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamp('deleted_at')->nullable();
            UserKey::column($table, 'deleted_by_id')->nullable();
            $table->text('deleted_reason')->nullable();
            UserKey::column($table, 'approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            UserKey::column($table, 'rejected_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
            $table->timestamp('edited_at')->nullable();
            UserKey::column($table, 'edited_by_id')->nullable();

            $table->index(['thread_id', 'created_at']);
            $table->index(['thread_id', 'is_deleted', 'created_at', 'id'], 'posts_thread_visible_order_idx');
            $table->index(['thread_id', 'id'], 'posts_thread_id_idx');
            $table->index(['created_at', 'id'], 'posts_created_at_id_idx');
            $table->index(['author_id', 'created_at'], 'posts_author_created_at_idx');
            $table->index(['updated_at', 'id'], 'posts_updated_at_id_idx');
            $table->index(['thread_id', 'moderation_status'], 'posts_thread_moderation_idx');
            $table->index(['thread_id', 'is_deleted', 'moderation_status', 'id'], 'posts_thread_visible_count_idx');
            $table->index(['author_id', 'is_deleted', 'moderation_status'], 'posts_author_visible_count_idx');
            $table->fullText('body');

            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('deleted_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('approved_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('rejected_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('post_reports'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('thread_id');
            UserKey::column($table, 'reporter_id');
            $table->text('reason');
            $table->string('status', 16)->default('open')->index();
            UserKey::column($table, 'reviewed_by_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'status']);
            $table->index(['thread_id', 'status']);
            $table->index(['reporter_id', 'created_at']);
            $table->index(['status', 'reviewed_at'], 'post_reports_status_reviewed_at_idx');
            $table->index(['reviewed_by_id', 'reviewed_at'], 'post_reports_reviewer_reviewed_at_idx');
            $table->index(['created_at', 'id'], 'post_reports_created_at_id_idx');

            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('reporter_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('post_moderation_logs'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('post_id');
            UserKey::column($table, 'moderator_id')->nullable();
            $table->string('action', 32)->index();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
            $table->index(['post_id', 'created_at']);

            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->cascadeOnDelete();
            $table->foreign('moderator_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('thread_moderation_logs'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            UserKey::column($table, 'moderator_id')->nullable();
            $table->string('action', 32)->index();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);

            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('moderator_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('thread_moderation_logs'));
        Schema::dropIfExists(Tables::name('post_moderation_logs'));
        Schema::dropIfExists(Tables::name('post_reports'));
        Schema::dropIfExists(Tables::name('posts'));
        Schema::dropIfExists(Tables::name('threads'));
        Schema::dropIfExists(Tables::name('forums'));
        Schema::dropIfExists(Tables::name('forum_groups'));
    }
};
