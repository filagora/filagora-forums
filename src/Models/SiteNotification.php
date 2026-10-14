<?php

declare(strict_types=1);

namespace Filagora\Forums\Models;

use Filagora\Core\Support\Tables;
use Filagora\Forums\Enums\AnnouncementAudience;
use Filagora\Forums\Enums\AnnouncementSeverity;
use Filagora\Forums\Enums\AnnouncementStatus;
use Filagora\Forums\Services\ActiveAnnouncements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A site-wide announcement (a banner). The table keeps the name of the forum this package was
 * extracted from, so existing sites adopt it as it is.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property AnnouncementStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property AnnouncementAudience $audience
 * @property AnnouncementSeverity $severity
 * @property int $priority
 * @property bool $is_dismissible
 * @property bool $is_persistent
 * @property array<int, mixed>|null $targeting_rules
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteNotification extends Model
{
    protected $fillable = [
        'title',
        'body',
        'status',
        'starts_at',
        'ends_at',
        'audience',
        'severity',
        'priority',
        'is_dismissible',
        'is_persistent',
        'targeting_rules',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => ActiveAnnouncements::flush());
        static::deleted(fn () => ActiveAnnouncements::flush());
    }

    public function getTable(): string
    {
        return Tables::name('site_notifications');
    }

    protected function casts(): array
    {
        return [
            'status' => AnnouncementStatus::class,
            'audience' => AnnouncementAudience::class,
            'severity' => AnnouncementSeverity::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'is_dismissible' => 'boolean',
            'is_persistent' => 'boolean',
            'targeting_rules' => 'array',
        ];
    }

    /**
     * Published and inside its window (a missing start or end is open).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', AnnouncementStatus::PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function matchesAudience(bool $isAuthenticated): bool
    {
        return $this->audience->matches($isAuthenticated);
    }
}
