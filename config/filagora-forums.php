<?php

declare(strict_types=1);
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;

return [

    /*
    |--------------------------------------------------------------------------
    | Swappable models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass
    | to replace one (here, or under `filagora.models.<key>`, which wins). Subclass
    | the package model; the package always asks for the class through its Models
    | helper, so relations and queries follow your class.
    |
    */
    'models' => [
        'forum_group' => ForumGroup::class,
        'forum' => Forum::class,
        'thread' => Thread::class,
        'post' => Post::class,
        'post_report' => PostReport::class,
        'post_moderation_log' => PostModerationLog::class,
        'thread_moderation_log' => ThreadModerationLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate abilities
    |--------------------------------------------------------------------------
    |
    | Maps each ability that marks a user as forum staff to the entitlement that grants it.
    | Holding any of them makes a user staff. Point an ability at your own entitlement key to
    | use your own keys; keys listed here are created as definitions by
    | `filagora:entitlements:sync`.
    |
    */
    'gates' => [
        'filagora.forums.moderate' => 'filagora.forums.moderate',
        'filagora.forums.view_unapproved' => 'filagora.forums.view_unapproved',
        'filagora.forums.bypass_approval' => 'filagora.forums.bypass_approval',
    ],

];
