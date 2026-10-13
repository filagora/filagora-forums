<?php

declare(strict_types=1);

namespace Filagora\Forums\Tests\Feature;

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Services\ForumAccess;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Tests\Support\TestUser;
use Filagora\Forums\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A host points the staff abilities at its own entitlement keys in config, before boot.
 */
class ForumAccessRemapTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filagora-forums.gates', [
            Abilities::MODERATE => 'operator.forum.moderate',
        ]);
    }

    public function test_staff_follow_the_remapped_entitlement_key(): void
    {
        $user = TestUser::make('Staff');
        $access = app(ForumAccess::class);

        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'filagora.forums.moderate', 'grant_type' => 'manual']);
        $this->assertFalse($access->isStaff($user), 'the package key no longer makes a user staff');

        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'operator.forum.moderate', 'grant_type' => 'manual']);
        $this->assertTrue($access->isStaff($user));
    }
}
