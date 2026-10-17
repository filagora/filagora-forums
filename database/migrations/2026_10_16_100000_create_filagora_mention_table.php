<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who has been told they were mentioned in which post, in the shape of the forum the package was
 * extracted from: one row per post and person, so editing a post never tells anyone twice and the
 * cap on mentions per post can count everyone ever notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('post_mentions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            UserKey::column($table, 'user_id');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            $table->index('user_id');

            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('post_mentions'));
    }
};
