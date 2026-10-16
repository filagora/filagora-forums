<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Support\Models;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Answers a thread search from the database: the `database` engine, and the fallback when the
 * search server fails. It understands the same filter tree the engines get, over the same field
 * names, and applies the same rules (approved threads in active forums).
 *
 * On MariaDB the words are matched with the FULLTEXT indexes on thread titles and post bodies
 * (candidates capped, so a broad query cannot scan the corpus); elsewhere, or with
 * `search.fulltext` off, with LIKE. Relevance has no database equivalent, so it falls back to the
 * most recent activity.
 */
class ThreadDatabaseSearch
{
    /** Cap on FULLTEXT candidate ids. */
    private const FULLTEXT_CANDIDATE_LIMIT = 1000;

    /** The most recently active threads per person that "started or posted in" covers. */
    private const POSTED_IN_LIMIT = 5000;

    /** @var array<int, list<int>> */
    private array $postedIn = [];

    public function search(SearchQuery $query): SearchResult
    {
        $threads = Models::class('thread');
        $builder = $threads::query()
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->whereHas('forum', fn (Builder $forum) => $forum->where('is_active', true));

        if ($query->filter !== null) {
            $this->apply($builder, $query->filter);
        }

        $sorts = $this->sorts($query);

        if (trim($query->text) !== '') {
            $this->applyText($builder, trim($query->text), clone $builder, $sorts[0][0]);
        }

        $total = (clone $builder)->count();

        foreach ($sorts as [$column, $direction]) {
            $builder->orderBy($column, $direction);
        }

        $ids = $builder->orderByDesc('id')->forPage($query->page, $query->perPage)->pluck('id');

        return new SearchResult($ids->map(static fn (mixed $id): Hit => new Hit((int) $id))->all(), $total);
    }

    /**
     * @return list<array{0: string, 1: string}> Column and direction, never empty.
     */
    private function sorts(SearchQuery $query): array
    {
        $columns = ['last_post_at_ts' => 'last_activity_at', 'created_at_ts' => 'created_at', 'reply_count' => 'reply_count'];
        $sorts = [];

        foreach ($query->sort as $sort) {
            if (isset($columns[$sort->field])) {
                $sorts[] = [$columns[$sort->field], $sort->direction->value];
            }
        }

        return $sorts === [] ? [['last_activity_at', 'desc']] : $sorts;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function apply(Builder $query, Filter $filter): void
    {
        match (true) {
            $filter instanceof Equals => $this->condition($query, $filter->field, [$filter->value]),
            $filter instanceof In => $this->condition($query, $filter->field, $filter->values),
            $filter instanceof Range => $this->range($query, $filter),
            $filter instanceof All => $query->where(function (Builder $all) use ($filter): void {
                foreach ($filter->filters as $inner) {
                    $all->where(fn (Builder $each) => $this->apply($each, $inner));
                }
            }),
            $filter instanceof Any => $filter->filters === []
                ? $query->whereRaw('1 = 0')
                : $query->where(function (Builder $any) use ($filter): void {
                    foreach ($filter->filters as $inner) {
                        $any->orWhere(fn (Builder $each) => $this->apply($each, $inner));
                    }
                }),
            $filter instanceof Not => $query->whereNot(fn (Builder $not) => $this->apply($not, $filter->filter)),
            default => throw new InvalidArgumentException('Unsupported filter '.$filter::class.'.'),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<int|string|bool>  $values
     */
    private function condition(Builder $query, string $field, array $values): void
    {
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        match ($field) {
            'forum_id', 'author_id' => $query->whereIn($query->getModel()->qualifyColumn($field), $values),
            'forum_is_active' => $query->whereHas('forum', fn (Builder $forum) => $forum->where('is_active', (bool) $values[0])),
            'post_author_ids' => $query->whereIn($query->getModel()->qualifyColumn('id'), $this->postedInThreadIds($values) ?: [0]),
            default => throw new InvalidArgumentException("[{$field}] cannot be filtered on in the database."),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function range(Builder $query, Range $range): void
    {
        $column = match ($range->field) {
            'reply_count' => 'reply_count',
            'created_at_ts' => 'created_at',
            'last_post_at_ts' => 'last_activity_at',
            default => throw new InvalidArgumentException("[{$range->field}] cannot be filtered on in the database."),
        };
        $isTime = $range->field !== 'reply_count';
        $qualified = $query->getModel()->qualifyColumn($column);

        foreach ([['>=', $range->gte], ['<=', $range->lte]] as [$operator, $bound]) {
            if ($bound !== null) {
                $query->where($qualified, $operator, $isTime ? date('Y-m-d H:i:s', (int) $bound) : $bound);
            }
        }
    }

    /**
     * Title or post body. MariaDB uses the FULLTEXT indexes; each candidate list is taken from
     * the already-filtered scope and ordered by the requested sort before the cap, so page one is
     * the true top of the sort rather than of an arbitrary subset.
     *
     * @param  Builder<Model>  $query
     * @param  Builder<Model>  $scope
     */
    private function applyText(Builder $query, string $text, Builder $scope, string $sortColumn): void
    {
        $postModel = Models::class('post');

        if (! config('filagora-forums.search.fulltext', true) || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%';
            $query->where(fn (Builder $match) => $match
                ->where($query->getModel()->qualifyColumn('title'), 'like', $like)
                ->orWhereHas('posts', fn (Builder $post) => $post
                    ->where('is_deleted', false)
                    ->where('moderation_status', ContentModerationStatus::APPROVED->value)
                    ->where('body', 'like', $like)));

            return;
        }

        $threads = $query->getModel()->getTable();
        $posts = (new $postModel)->getTable();
        $phrase = '"'.str_replace('"', '', $text).'"';

        $titleIds = (clone $scope)
            ->whereFullText("{$threads}.title", $phrase, ['mode' => 'boolean'])
            ->orderByDesc("{$threads}.{$sortColumn}")
            ->limit(self::FULLTEXT_CANDIDATE_LIMIT)
            ->pluck("{$threads}.id");

        $postThreadIds = $postModel::query()
            ->join($threads, "{$threads}.id", '=', "{$posts}.thread_id")
            ->where("{$posts}.is_deleted", false)
            ->where("{$posts}.moderation_status", ContentModerationStatus::APPROVED->value)
            ->whereFullText("{$posts}.body", $phrase, ['mode' => 'boolean'])
            ->whereIn("{$posts}.thread_id", (clone $scope)->select("{$threads}.id"))
            ->groupBy("{$posts}.thread_id")
            ->orderByRaw("MAX({$threads}.{$sortColumn}) DESC")
            ->limit(self::FULLTEXT_CANDIDATE_LIMIT)
            ->pluck("{$posts}.thread_id");

        $query->whereIn("{$threads}.id", $titleIds->merge($postThreadIds)->unique()->values()->all() ?: [0]);
    }

    /**
     * Threads any of these people started or has a visible post in, most recently active first and
     * capped, as two queries merged in PHP (a single IN over a UNION is not semi-join optimised by
     * MariaDB and re-runs for every thread).
     *
     * @param  list<int|string|bool>  $userIds
     * @return list<int>
     */
    private function postedInThreadIds(array $userIds): array
    {
        $ids = [];

        foreach ($userIds as $userId) {
            $ids = [...$ids, ...$this->postedInFor((int) $userId)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function postedInFor(int $userId): array
    {
        if (isset($this->postedIn[$userId])) {
            return $this->postedIn[$userId];
        }

        $threadModel = Models::class('thread');
        $postModel = Models::class('post');
        $threads = (new $threadModel)->getTable();
        $posts = (new $postModel)->getTable();

        $activityById = $threadModel::query()
            ->where('author_id', $userId)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::POSTED_IN_LIMIT)
            ->toBase()
            ->pluck('last_activity_at', 'id')
            ->all();

        $postedIn = $postModel::query()
            ->join($threads, "{$threads}.id", '=', "{$posts}.thread_id")
            ->where("{$posts}.author_id", $userId)
            ->where("{$posts}.is_deleted", false)
            ->where("{$posts}.moderation_status", ContentModerationStatus::APPROVED->value)
            ->groupBy("{$posts}.thread_id")
            ->orderByRaw("MAX({$threads}.last_activity_at) DESC")
            ->orderByDesc("{$posts}.thread_id")
            ->limit(self::POSTED_IN_LIMIT)
            ->selectRaw("{$posts}.thread_id as thread_id, MAX({$threads}.last_activity_at) as activity")
            ->toBase()
            ->get();

        foreach ($postedIn as $row) {
            $activityById[(int) $row->thread_id] = $row->activity;
        }

        $ids = array_keys($activityById);
        usort($ids, static fn (int $a, int $b): int => [$activityById[$b], $b] <=> [$activityById[$a], $a]);

        return $this->postedIn[$userId] = array_map('intval', array_slice($ids, 0, self::POSTED_IN_LIMIT));
    }
}
