<?php

declare(strict_types=1);

namespace Filagora\Forums\Support;

use Filagora\Entitlements\Contracts\EntitlementKeySource;

/**
 * The entitlement keys set on forums, so the definitions catalogue can create a definition for
 * each (`filagora:entitlements:sync`).
 */
final class ForumEntitlementKeys implements EntitlementKeySource
{
    public function keys(): array
    {
        $forum = Models::class('forum');
        $keys = [];

        foreach (['required_entitlement_scope', 'reply_entitlement_scope'] as $column) {
            foreach ($forum::query()->whereNotNull($column)->distinct()->pluck($column) as $value) {
                $key = trim((string) $value);

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
