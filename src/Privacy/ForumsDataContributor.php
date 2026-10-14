<?php

declare(strict_types=1);

namespace Filagora\Forums\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Forums\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * What the forums hold about a person, for the compliance package's export: their threads, their
 * posts and the reports they filed. Erasure removes nothing: what a person wrote stays, attributed
 * to the account that the host's anonymiser has by then made anonymous.
 */
final class ForumsDataContributor implements PrivacyContributor
{
    public function key(): string
    {
        return 'forums';
    }

    public function export(Model $user): array
    {
        $id = $user->getKey();

        return [
            'threads' => Models::class('thread')::query()->where('author_id', $id)->orderBy('id')->get(['id', 'forum_id', 'title', 'slug', 'created_at', 'updated_at'])->toArray(),
            'posts' => Models::class('post')::query()->where('author_id', $id)->orderBy('id')->get(['id', 'thread_id', 'body', 'created_at', 'updated_at'])->toArray(),
            'post_reports' => Models::class('post_report')::query()->where('reporter_id', $id)->orderBy('id')->get(['id', 'post_id', 'reason', 'status', 'created_at'])->toArray(),
        ];
    }

    public function erase(Model $user): void
    {
        // Authored content stays, under the anonymised account.
    }
}
