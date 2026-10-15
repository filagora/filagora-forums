<?php

declare(strict_types=1);

namespace Filagora\Forums\Subscriptions;

use Filagora\Forums\Contracts\SubscriptionPreferences;
use Filagora\Forums\Enums\DeliveryMode;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default preferences: two attributes of the user model. A user (or a user model) without them
 * gets updates at once and does not follow threads by posting.
 */
final class UserAttributePreferences implements SubscriptionPreferences
{
    public function deliveryMode(Authenticatable $user): DeliveryMode
    {
        $attribute = (string) config('filagora-forums.subscriptions.user_attributes.frequency', 'thread_subscription_email_frequency');
        $value = $attribute !== '' ? data_get($user, $attribute) : null;

        return is_string($value) ? (DeliveryMode::tryFrom(strtolower(trim($value))) ?? DeliveryMode::INSTANT) : DeliveryMode::INSTANT;
    }

    public function autoSubscribeOnPost(Authenticatable $user): bool
    {
        $attribute = (string) config('filagora-forums.subscriptions.user_attributes.auto_subscribe', 'auto_subscribe_on_post');
        $value = $attribute !== '' ? data_get($user, $attribute) : null;

        return $value === null ? (bool) config('filagora-forums.subscriptions.auto_subscribe_default', false) : (bool) $value;
    }
}
