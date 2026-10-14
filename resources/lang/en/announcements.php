<?php

declare(strict_types=1);

return [
    'resource' => [
        'navigation_label' => 'Announcements',
        'model_label' => 'announcement',
        'plural_model_label' => 'announcements',
    ],

    'form' => [
        'content' => 'Content',
        'title' => 'Title',
        'body' => 'Body',
        'publishing' => 'Publishing',
        'status' => 'Status',
        'starts_at' => 'Starts',
        'starts_at_help' => 'Leave empty to start as soon as it is published.',
        'ends_at' => 'Ends',
        'ends_at_help' => 'Leave empty to keep it until it is unpublished.',
        'display' => 'Display',
        'audience' => 'Audience',
        'severity' => 'Severity',
        'priority' => 'Priority',
        'priority_help' => 'Higher numbers are shown first.',
        'is_dismissible' => 'Visitors can dismiss it',
        'is_dismissible_help' => 'When off, the banner stays for as long as it is published and inside its dates.',
        'is_persistent' => 'Keep showing it',
        'is_persistent_help' => 'When off, a signed-in user sees it once and it is then gone for them.',
        'targeting' => 'Targeting',
        'targeting_help' => 'Show only to visitors who pass every rule below. With no rules, everyone in the audience sees it.',
        'rules' => 'Rules',
        'add_rule' => 'Add rule',
        'rule_type' => 'Rule',
    ],

    'table' => [
        'dismissible' => 'Dismissible',
        'persistent' => 'Persistent',
    ],

    'rules' => [
        'entitlement' => 'Has an entitlement',
        'username' => 'Is a specific user',
        'date_is' => 'Today is a date',
        'time_between' => 'Time of day is between',
        'never_posted' => 'Has never posted',
        'post_count_between' => 'Post count is between',
        'not_posted_days' => 'Has not posted for a number of days',
        'browsing_forum' => 'Is browsing a forum',
    ],

    'fields' => [
        'entitlement' => 'Entitlement',
        'negate' => 'Show to users who do NOT have it',
        'username' => 'Username',
        'month' => 'Month (1-12)',
        'day' => 'Day (1-31)',
        'start' => 'Start time',
        'end' => 'End time',
        'min_posts' => 'Minimum posts',
        'max_posts' => 'Maximum posts',
        'days' => 'Days',
        'forum' => 'Forum',
    ],
];
