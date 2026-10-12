<?php

declare(strict_types=1);

namespace ForumKit\Forums\Tests\Feature;

use ForumKit\Entitlements\Models\UserEntitlementGrant;
use ForumKit\Forums\Services\ForumAccess;
use ForumKit\Forums\Support\Abilities;
use ForumKit\Forums\Tests\Support\TestUser;
use ForumKit\Forums\Tests\TestCase;
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

        $app['config']->set('forumkit-forums.gates', [
            Abilities::MODERATE => 'operator.forum.moderate',
        ]);
    }

    public function test_staff_follow_the_remapped_entitlement_key(): void
    {
        $user = TestUser::make('Staff');
        $access = app(ForumAccess::class);

        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'forumkit.forums.moderate', 'grant_type' => 'manual']);
        $this->assertFalse($access->isStaff($user), 'the package key no longer makes a user staff');

        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => 'operator.forum.moderate', 'grant_type' => 'manual']);
        $this->assertTrue($access->isStaff($user));
    }
}
