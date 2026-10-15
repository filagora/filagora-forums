<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reactions to posts (a "like"), in the shape of the forum the package was extracted from: one
 * row per user, post and kind of reaction. The kind is free text so a site can offer more than one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('reactions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            UserKey::column($table, 'user_id');
            $table->string('type', 32)->default('like');
            $table->timestamps();

            $table->unique(['post_id', 'user_id', 'type']);
            $table->index(['post_id', 'type']);
            $table->index(['user_id', 'type']);

            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('reactions'));
    }
};
