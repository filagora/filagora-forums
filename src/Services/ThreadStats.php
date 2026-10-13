<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Support\Models;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes each thread's `reply_count` and last-post fields from its posts.
 *
 * Only approved posts that are not deleted count: `reply_count` is the number of those minus the
 * opening post, and the last post is the latest of them. (The forum this was extracted from
 * computed these three slightly different ways in different places; this is the one definition.)
 */
class ThreadStats
{
    public const CHUNK_SIZE = 1000;

    /**
     * @param  list<int>  $threadIds
     * @param  null|callable(int, int, int, int): void  $progress  Called after each chunk with chunk index, chunk count, threads done, threads total.
     * @return array{thread_ids: int, threads_updated: int, runtime_seconds: float}
     */
    public function syncForThreadIds(array $threadIds, ?callable $progress = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $threadIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return ['thread_ids' => 0, 'threads_updated' => 0, 'runtime_seconds' => 0.0];
        }

        $startedAt = microtime(true);
        $threads = (new (Models::class('thread')))->getTable();
        $posts = (new (Models::class('post')))->getTable();
        $approved = ContentModerationStatus::APPROVED->value;
        $updated = 0;
        $chunks = array_chunk($ids, self::CHUNK_SIZE);

        foreach ($chunks as $index => $chunk) {
            $counts = DB::table($posts)
                ->select('thread_id', DB::raw('COUNT(*) as total'))
                ->whereIn('thread_id', $chunk)
                ->where('is_deleted', false)
                ->where('moderation_status', $approved)
                ->groupBy('thread_id')
                ->pluck('total', 'thread_id');

            $latestPerThread = DB::table($posts)
                ->select('thread_id', DB::raw('MAX(created_at) as max_created_at'))
                ->whereIn('thread_id', $chunk)
                ->where('is_deleted', false)
                ->where('moderation_status', $approved)
                ->groupBy('thread_id');

            $rows = DB::table("{$posts} as p")
                ->joinSub($latestPerThread, 'lp', function ($join): void {
                    $join->on('lp.thread_id', '=', 'p.thread_id');
                    $join->on('lp.max_created_at', '=', 'p.created_at');
                })
                ->where('p.is_deleted', false)
                ->where('p.moderation_status', $approved)
                ->orderBy('p.thread_id')
                ->orderByDesc('p.id')
                ->get(['p.thread_id', 'p.author_id', 'p.created_at', 'p.id']);

            $latest = [];

            foreach ($rows as $row) {
                $latest[(int) $row->thread_id] ??= $row;
            }

            foreach ($chunk as $threadId) {
                $total = (int) ($counts[$threadId] ?? 0);
                $last = $latest[$threadId] ?? null;

                DB::table($threads)->where('id', $threadId)->update([
                    'reply_count' => max(0, $total - 1),
                    'last_post_author_id' => $last !== null ? (int) $last->author_id : null,
                    'last_post_at' => $last !== null ? (string) $last->created_at : null,
                    'updated_at' => now(),
                ]);

                $updated++;
            }

            if ($progress !== null) {
                $progress($index + 1, count($chunks), min(($index + 1) * self::CHUNK_SIZE, count($ids)), count($ids));
            }
        }

        return [
            'thread_ids' => count($ids),
            'threads_updated' => $updated,
            'runtime_seconds' => microtime(true) - $startedAt,
        ];
    }
}
