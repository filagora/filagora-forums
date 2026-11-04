<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * People following a whole forum, to hear of new threads in it, in the shape of the forum the
 * package was extracted from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('forum_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('forum_id');
            UserKey::column($table, 'user_id');
            $table->timestamps();

            $table->unique(['forum_id', 'user_id']);
            $table->index(['user_id', 'created_at']);

            $table->foreign('forum_id')->references('id')->on(Tables::name('forums'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('forum_subscriptions'));
    }
};
