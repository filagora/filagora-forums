<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A forum staff can keep out of search results: the host sends noindex for the forum and its threads
 * and leaves it out of its sitemap. Members can still read it. Guarded, because a site that already
 * has the column (the one this package was extracted from) is adopted, not migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = Tables::name('forums');

        if (Schema::hasColumn($table, 'noindex')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->boolean('noindex')->default(false);
        });
    }

    public function down(): void
    {
        $table = Tables::name('forums');

        if (Schema::hasColumn($table, 'noindex')) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('noindex');
            });
        }
    }
};
