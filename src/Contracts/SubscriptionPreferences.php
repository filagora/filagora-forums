<?php

declare(strict_types=1);

namespace Filagora\Forums\Contracts;

use Filagora\Forums\Enums\DeliveryMode;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a person has chosen about thread subscriptions. The package does not own these choices
 * (they live with the host's user settings or notification preferences), so it asks. The default
 * reads two attributes of the user model, set in `filagora-forums.subscriptions.user_attributes`.
 */
interface SubscriptionPreferences
{
    /**
     * How they want to hear about new posts in followed threads; `OFF` means not at all.
     */
    public function deliveryMode(Authenticatable $user): DeliveryMode;

    /**
     * Whether posting in a thread follows it automatically.
     */
    public function autoSubscribeOnPost(Authenticatable $user): bool;
}
