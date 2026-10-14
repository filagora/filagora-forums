<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Polls on threads, in the shape of the forum the package was extracted from: one poll per
 * thread, its questions and options, one vote per user and the options each vote chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('polls'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id')->unique();
            UserKey::column($table, 'created_by_id')->nullable();
            $table->string('status', 16)->default('open')->index();
            UserKey::column($table, 'closed_by_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
            $table->foreign('closed_by_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });

        Schema::create(Tables::name('poll_questions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('poll_id');
            $table->string('prompt', 500);
            $table->boolean('is_multi_select')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['poll_id', 'sort_order']);
            $table->foreign('poll_id')->references('id')->on(Tables::name('polls'))->cascadeOnDelete();
        });

        Schema::create(Tables::name('poll_options'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('poll_question_id');
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['poll_question_id', 'sort_order']);
            $table->foreign('poll_question_id')->references('id')->on(Tables::name('poll_questions'))->cascadeOnDelete();
        });

        Schema::create(Tables::name('poll_votes'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('poll_id');
            UserKey::column($table, 'user_id');
            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
            $table->foreign('poll_id')->references('id')->on(Tables::name('polls'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });

        Schema::create(Tables::name('poll_vote_answers'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('poll_vote_id');
            $table->unsignedBigInteger('poll_question_id');
            $table->unsignedBigInteger('poll_option_id');
            $table->timestamps();

            $table->unique(['poll_vote_id', 'poll_option_id']);
            $table->index(['poll_question_id', 'poll_option_id']);
            $table->foreign('poll_vote_id')->references('id')->on(Tables::name('poll_votes'))->cascadeOnDelete();
            $table->foreign('poll_question_id')->references('id')->on(Tables::name('poll_questions'))->cascadeOnDelete();
            $table->foreign('poll_option_id')->references('id')->on(Tables::name('poll_options'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('poll_vote_answers'));
        Schema::dropIfExists(Tables::name('poll_votes'));
        Schema::dropIfExists(Tables::name('poll_options'));
        Schema::dropIfExists(Tables::name('poll_questions'));
        Schema::dropIfExists(Tables::name('polls'));
    }
};
