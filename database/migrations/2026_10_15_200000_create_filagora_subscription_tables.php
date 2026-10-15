<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Thread subscriptions and the digest queue, in the shape of the forum the package was extracted
 * from. One index name would pass MariaDB's 64 character limit once the table prefix is added, so
 * it is written out (the name that forum has), unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('thread_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            UserKey::column($table, 'user_id');
            $table->timestamps();

            $table->unique(['thread_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'thread_id'], 'thread_subscriptions_user_thread_idx');

            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });

        Schema::create(Tables::name('thread_subscription_digest_items'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('post_id');
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'post_id']);
            $table->index(['user_id', 'created_at'], 'thread_subscription_digest_items_user_id_created_at_index');
            $table->index(['user_id', 'sent_at'], 'digest_items_user_sent_idx');
            $table->index('thread_id', 'digest_items_thread_idx');

            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('thread_subscription_digest_items'));
        Schema::dropIfExists(Tables::name('thread_subscriptions'));
    }
};
