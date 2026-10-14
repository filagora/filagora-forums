<?php

declare(strict_types=1);

namespace Filagora\Forums\Targeting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who is looking at the page: the signed-in user (or none) and what the host knows about the
 * request, such as `['forum_id' => 12]` on a forum page.
 */
final readonly class Viewer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ?Authenticatable $user = null,
        public array $context = [],
    ) {}

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    /**
     * The viewer's own clock: their timezone if they have one, the application's otherwise.
     */
    public function now(): CarbonImmutable
    {
        $attribute = (string) config('filagora-forums.announcements.timezone_attribute', 'timezone');
        $timezone = $this->user !== null && $attribute !== '' ? data_get($this->user, $attribute) : null;

        return CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : config('app.timezone'));
    }
}
