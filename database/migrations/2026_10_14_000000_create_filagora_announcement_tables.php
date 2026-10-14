<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site announcement tables, in the shape of the forum the package was extracted from. The
 * constraint names are written out (the names that forum has) because the name Laravel would
 * generate grows with the table prefix and would pass MariaDB's 64 character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('site_notifications'), function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('draft')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('audience')->default('everyone')->index();
            $table->string('severity')->default('info')->index();
            $table->unsignedInteger('priority')->default(0)->index();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_persistent')->default(true);
            $table->json('targeting_rules')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create(Tables::name('user_site_notification_dismissals'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            $table->unsignedBigInteger('site_notification_id');
            $table->timestamp('dismissed_at')->useCurrent();

            $table->unique(['user_id', 'site_notification_id'], 'user_site_notification_dismissals_unique');

            $table->foreign('user_id', 'user_site_notification_dismissals_user_id_foreign')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('site_notification_id', 'user_site_notification_dismissals_site_notification_id_foreign')->references('id')->on(Tables::name('site_notifications'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('user_site_notification_dismissals'));
        Schema::dropIfExists(Tables::name('site_notifications'));
    }
};
