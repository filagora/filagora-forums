<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Files attached to posts, in the shape of the forum the package was extracted from. A file is
 * uploaded first (no post or thread yet: an inline image written into a post being composed) and
 * claimed when the post is saved; its links to the post and thread are cleared, not cascaded, when
 * those go, so the files can be cleaned up. Private messages link their own attachments by adding
 * columns here (see `filagora-messages`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('post_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('thread_id')->nullable();
            UserKey::column($table, 'uploader_id')->nullable();
            $table->string('original_filename')->default('');
            $table->text('caption')->nullable();
            $table->string('extension', 32)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('filesize_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('thumbnail_width')->nullable();
            $table->unsignedInteger('thumbnail_height')->nullable();
            $table->string('filehash', 64)->nullable()->index();
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 1024);
            $table->string('thumbnail_storage_path', 1024)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on(Tables::name('posts'))->nullOnDelete();
            $table->foreign('thread_id')->references('id')->on(Tables::name('threads'))->nullOnDelete();
            $table->foreign('uploader_id')->references('id')->on(UserModel::table())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('post_attachments'));
    }
};
