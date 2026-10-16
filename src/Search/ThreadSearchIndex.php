<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Filagora\Forums\Enums\ContentModerationStatus;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Support\Models;
use Filagora\Search\Contracts\GroupedSearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Threads as search documents: approved threads in active forums, with the forum's name and the
 * text of their approved posts copied in so the engine can rank without a join. The fields the
 * forum search filters and sorts on form the `filters` group, so they can be back-filled on their
 * own (`filagora:search:backfill threads filters`).
 */
final class ThreadSearchIndex implements GroupedSearchIndex
{
    public const NAME = 'threads';

    public const GROUP_FILTERS = 'filters';

    public function __construct(private readonly ThreadDatabaseSearch $database) {}

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: ['title', 'forum_name', 'slug', 'post_search_text'],
            filterable: ['forum_id', 'forum_is_active', 'author_id', 'post_author_ids', 'reply_count', 'created_at_ts', 'last_post_at_ts'],
            sortable: ['last_post_at_ts', 'created_at_ts', 'reply_count'],
        );
    }

    public function model(): string
    {
        return Models::class('thread');
    }

    public function shouldBeSearchable(Model $model): bool
    {
        if (! $model instanceof Thread || $model->moderation_status !== ContentModerationStatus::APPROVED) {
            return false;
        }

        $forum = $model->forum;

        return $forum instanceof Forum && $forum->is_active;
    }

    public function toDocument(Model $model): Document
    {
        assert($model instanceof Thread);
        $forum = $model->forum;

        return new Document($model->getKey(), [
            'forum_id' => (int) $model->forum_id,
            'author_id' => (int) $model->author_id,
            'title' => $model->title,
            'slug' => $model->slug,
            'forum_name' => $forum instanceof Forum ? $forum->name : '',
            'forum_is_active' => $forum instanceof Forum && $forum->is_active,
            'moderation_status' => $model->moderation_status->value,
            'is_pinned' => $model->is_pinned,
            'is_locked' => $model->is_locked,
            ...$this->filterFields($model),
            'post_search_text' => $this->postText($model),
        ]);
    }

    public function query(): Builder
    {
        return Models::class('thread')::query()
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->whereHas('forum', fn (Builder $forum) => $forum->where('is_active', true))
            ->with('forum');
    }

    public function expectedCount(): int
    {
        return Models::class('thread')::query()
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->whereHas('forum', fn (Builder $forum) => $forum->where('is_active', true))
            ->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return $this->database->search($query);
    }

    public function groups(): array
    {
        return [self::GROUP_FILTERS];
    }

    public function toPartialDocument(Model $model, string $group): Document
    {
        if ($group !== self::GROUP_FILTERS) {
            throw new InvalidArgumentException("[{$group}] is not a field group of the threads index.");
        }

        assert($model instanceof Thread);

        return new Document($model->getKey(), $this->filterFields($model));
    }

    /**
     * @return array{post_author_ids: list<int>, created_at_ts: int, last_post_at_ts: int, reply_count: int}
     */
    private function filterFields(Thread $thread): array
    {
        $authors = Models::class('post')::query()
            ->where('thread_id', $thread->getKey())
            ->where('is_deleted', false)
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->whereNotNull('author_id')
            ->distinct()
            ->pluck('author_id')
            ->map(static fn (mixed $id): int => (int) $id);

        $created = $thread->created_at?->getTimestamp() ?? 0;

        return [
            'post_author_ids' => $authors->push((int) $thread->author_id)->unique()->sort()->values()->all(),
            'created_at_ts' => $created,
            'last_post_at_ts' => max($created, $thread->last_post_at?->getTimestamp() ?? 0),
            'reply_count' => (int) $thread->reply_count,
        ];
    }

    private function postText(Thread $thread): string
    {
        $limit = max(1, (int) config('filagora-forums.search.post_text_limit', 50000));
        $text = '';

        foreach (Models::class('post')::query()
            ->where('thread_id', $thread->getKey())
            ->where('is_deleted', false)
            ->where('moderation_status', ContentModerationStatus::APPROVED->value)
            ->orderBy('id')
            ->lazyById(200) as $post) {
            assert($post instanceof Post);
            $text .= ' '.trim((string) preg_replace('/\s+/', ' ', strip_tags($post->body)));

            if (mb_strlen($text) >= $limit) {
                break;
            }
        }

        return mb_substr(trim($text), 0, $limit);
    }
}
