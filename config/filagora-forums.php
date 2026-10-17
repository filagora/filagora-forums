<?php

declare(strict_types=1);
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\ForumRead;
use Filagora\Forums\Models\Poll;
use Filagora\Forums\Models\PollOption;
use Filagora\Forums\Models\PollQuestion;
use Filagora\Forums\Models\PollVote;
use Filagora\Forums\Models\PollVoteAnswer;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostAttachment;
use Filagora\Forums\Models\PostMention;
use Filagora\Forums\Models\PostModerationLog;
use Filagora\Forums\Models\PostReport;
use Filagora\Forums\Models\Reaction;
use Filagora\Forums\Models\SiteNotification;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Models\ThreadModerationLog;
use Filagora\Forums\Models\ThreadRead;
use Filagora\Forums\Models\ThreadSubscription;
use Filagora\Forums\Models\ThreadSubscriptionDigestItem;
use Filagora\Forums\Models\UserSiteNotificationDismissal;

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
        'site_notification' => SiteNotification::class,
        'site_notification_dismissal' => UserSiteNotificationDismissal::class,
        'poll' => Poll::class,
        'poll_question' => PollQuestion::class,
        'poll_option' => PollOption::class,
        'poll_vote' => PollVote::class,
        'poll_vote_answer' => PollVoteAnswer::class,
        'reaction' => Reaction::class,
        'thread_subscription' => ThreadSubscription::class,
        'thread_subscription_digest_item' => ThreadSubscriptionDigestItem::class,
        'forum_read' => ForumRead::class,
        'thread_read' => ThreadRead::class,
        'post_mention' => PostMention::class,
        'post_attachment' => PostAttachment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate abilities
    |--------------------------------------------------------------------------
    |
    | Maps each ability to the entitlement that grants it. Holding any of the first three
    | makes a user forum staff; `filagora.forums.announcements` only opens the announcements
    | screen. Point an ability at your own entitlement key to
    | use your own keys; keys listed here are created as definitions by
    | `filagora:entitlements:sync`.
    |
    */
    'gates' => [
        'filagora.forums.moderate' => 'filagora.forums.moderate',
        'filagora.forums.view_unapproved' => 'filagora.forums.view_unapproved',
        'filagora.forums.bypass_approval' => 'filagora.forums.bypass_approval',
        'filagora.forums.announcements' => 'filagora.forums.announcements',
    ],

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    |
    | The columns of your user model the announcement targeting rules read: the username, and
    | the timezone the date and time-of-day rules use (the application's timezone when a user
    | has none).
    |
    */
    'announcements' => [
        'username_attribute' => 'username',
        'timezone_attribute' => 'timezone',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long the moderation logs, and reports staff dismissed, are kept (days; zero keeps them
    | for ever). They are registered with core's RetentionRuleRegistry, which
    | `filagora:compliance:prune` (filagora-compliance) runs; without that package nothing
    | deletes them.
    |
    */
    'retention' => [
        'thread_moderation_logs_days' => 1095,
        'post_moderation_logs_days' => 1095,
        'dismissed_reports_days' => 90,
        'digest_items_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    |
    | The kinds of reaction a person can make to a post. The kind is stored as text (up to 32
    | characters), so adding one needs no migration.
    |
    */
    'reactions' => [
        'types' => ['like'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | Following threads. `user_attributes` names the columns of your user model holding a person's
    | delivery choice (instant, daily, weekly or off) and whether posting follows a thread; bind
    | your own Filagora\Forums\Contracts\SubscriptionPreferences to read them elsewhere. `mail`
    | switches the default emails off (listen to SubscribersToNotify and ThreadDigestReady instead).
    | `routes.thread` is the named route of your thread page (it receives `thread`), used for the
    | links in the mail.
    |
    */
    'subscriptions' => [
        'user_attributes' => [
            'frequency' => 'thread_subscription_email_frequency',
            'auto_subscribe' => 'auto_subscribe_on_post',
        ],
        'auto_subscribe_default' => false,
        'mail' => ['enabled' => true],
        'routes' => ['thread' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Files on posts. `disk` and `directory`: where they are stored. `max_per_hour`: the most a
    | person may upload in an hour. `thumbnail_width`: images are scaled down to this width for a
    | thumbnail when `intervention/image` is installed. `inline_pattern`: the regular expression
    | that finds, in a post body, the id of an uploaded file embedded in it (the first group is
    | the id); match your attachment URLs. `orphan_hours`: how long an upload may wait for its
    | post before it is cleaned up (zero keeps them).
    |
    */
    'attachments' => [
        'disk' => 'local',
        'directory' => 'attachments/uploads',
        'max_per_hour' => 30,
        'thumbnail_width' => 640,
        'inline_pattern' => '#post-attachments/(\d+)#',
        'orphan_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mentions
    |--------------------------------------------------------------------------
    |
    | `@name` in a post. `enabled` switches the automatic handling of new, edited and approved
    | posts off (call `Mentions::sync()` yourself). `user_attribute` is the user column the names
    | are matched against, ignoring case. `max_per_post` is the most people ever told about one
    | post. `mail` switches the default email off (listen to UsersMentioned instead);
    | `routes.thread` is the named route of your thread page (it receives `thread`; the link gets
    | `#post-{id}` added).
    |
    */
    'mentions' => [
        'enabled' => true,
        'user_attribute' => 'username',
        'max_per_post' => 10,
        'mail' => ['enabled' => true],
        'routes' => ['thread' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Read tracking
    |--------------------------------------------------------------------------
    |
    | `baseline_attribute`: the column of your user model holding the moment before which
    | everything counts as read for that person (null or blank for none). `unread_max_lookback_days`:
    | how far back a "what's new" list looks at most, whatever the baseline (an old baseline would
    | otherwise match every thread).
    |
    */
    'read_tracking' => [
        'baseline_attribute' => 'forum_read_baseline_at',
        'unread_max_lookback_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Used when `filagora/filagora-search` is installed. `fulltext`: answer from MariaDB's
    | FULLTEXT indexes on the database path (off falls back to LIKE). `post_text_limit`: the most
    | characters of a thread's post text copied into its search document.
    |
    */
    'search' => [
        'fulltext' => true,
        'post_text_limit' => 50000,
    ],

];
