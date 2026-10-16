<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which forums and threads each person has read, in the shape of the forum the package was
 * extracted from: when they last read a forum or thread (one row each), and for a thread whether
 * they flagged it unread by hand. A person's baseline, before which everything counts as read, is
 * a column of the host's user table (see `read_tracking.baseline_attribute`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('forum_reads'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            $table->unsignedBigInteger('forum_id');
            $table->timestamp('last_read_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'forum_id']);

            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('forum_id')->references('id')->on(Tables::name('forums'))->cascadeOnDelete();
        });

        Schema::create(Tables::name('thread_reads'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            $table->unsignedBigInteger('thread_id');
            $table->timestamp('last_read_at')->index();
            $table->timestamps();
            $table->boolean('is_unread_override')->default(false);

            $table->unique(['user_id', 'thread_id']);

            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('thread_reads'));
        Schema::dropIfExists(Tables::name('forum_reads'));
    }
};
