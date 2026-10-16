<?php

declare(strict_types=1);

namespace Filagora\Forums\Search;

use Carbon\CarbonImmutable;

/**
 * What a forum search asked for, already normalised: unknown values have fallen back to defaults
 * and the author has been resolved from a name to an id (the host does both from the request).
 */
final readonly class ForumSearchCriteria
{
    /** @var array<string, string> since key => interval */
    public const SINCE_OPTIONS = [
        '1d' => '1 day',
        '7d' => '7 days',
        '30d' => '30 days',
        '1y' => '1 year',
    ];

    /** @var list<string> */
    public const SORTS = ['last_post', 'relevance', 'newest', 'replies'];

    /** @var list<string> */
    public const POST_SORTS = ['oldest', 'newest'];

    /** @var list<string> */
    public const AUTHOR_MODES = ['started', 'posted'];

    /**
     * @param  string  $authorMode  `started` (the author began the thread) or `posted` (began it or has a visible post in it)
     * @param  string|null  $since  One of the SINCE_OPTIONS keys: threads active within that period
     * @param  int|string|null  $mineUserId  Only threads this person started or posted in
     */
    public function __construct(
        public string $query = '',
        public int|string|null $forumId = null,
        public int|string|null $authorId = null,
        public string $authorMode = 'started',
        public ?string $since = null,
        public int $minReplies = 0,
        public int|string|null $mineUserId = null,
        public string $sort = 'last_post',
        public bool $includeUnapproved = false,
        public string $postSort = 'oldest',
    ) {}

    public function sinceTimestamp(): ?int
    {
        if ($this->since === null || ! isset(self::SINCE_OPTIONS[$this->since])) {
            return null;
        }

        return CarbonImmutable::now()->sub(self::SINCE_OPTIONS[$this->since])->getTimestamp();
    }

    /**
     * Relevance only means something with search words; without them results fall back to the
     * most recent activity.
     */
    public function effectiveSort(): string
    {
        return $this->sort === 'relevance' && $this->query === '' ? 'last_post' : $this->sort;
    }
}
