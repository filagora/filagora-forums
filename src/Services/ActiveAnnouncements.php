<?php

declare(strict_types=1);

namespace Filagora\Forums\Services;

use Filagora\Forums\Models\SiteNotification;
use Filagora\Forums\Support\Models;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Forums\Targeting\Viewer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The announcements to show one viewer: those that are live now, for their audience, whose
 * targeting rules they pass, highest priority first.
 */
class ActiveAnnouncements
{
    public const CACHE_KEY = 'site_notifications:active_pool';

    private const CACHE_SECONDS = 60;

    public function __construct(private readonly TargetingRules $rules) {}

    /**
     * Forget the shared pool; saving or deleting an announcement does this.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $context  What the host knows about the request, for example `['forum_id' => 12]`.
     * @return Collection<int, SiteNotification>
     */
    public function forViewer(?Authenticatable $user, array $context = []): Collection
    {
        // Only ids are cached, not models: some cache stores cannot round-trip Eloquent objects,
        // and this runs on every page. The pool is the same for everyone; audience and rules
        // depend on the viewer and are applied to the small pool afterwards.
        $ids = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->model()::query()
            ->active()
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->pluck('id')
            ->all());

        if ($ids === []) {
            return new Collection;
        }

        $viewer = new Viewer($user, $context);

        return $this->model()::query()->whereIn('id', $ids)->get()
            ->sortBy(fn (SiteNotification $announcement): int|string|false => array_search($announcement->id, $ids, true))
            ->filter(fn (SiteNotification $announcement): bool => $announcement->matchesAudience($user !== null))
            ->filter(fn (SiteNotification $announcement): bool => $this->rules->passes($announcement->targeting_rules, $viewer))
            ->values();
    }

    /**
     * @return class-string<SiteNotification>
     */
    private function model(): string
    {
        /** @var class-string<SiteNotification> */
        return Models::class('site_notification');
    }
}
