<?php

declare(strict_types=1);

return [
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
