<?php

declare(strict_types=1);

return [
    'updated' => [
        'subject' => 'New post in :thread',
        'intro' => 'There is a new post in a thread you follow: :thread.',
        'action' => 'Read the thread',
    ],

    'new_thread' => [
        'subject' => 'New thread in :forum: :thread',
        'intro' => 'A new thread was started in a forum you follow, :forum: :thread.',
        'action' => 'Read the thread',
    ],

    'digest' => [
        'subject_daily' => 'Your daily thread digest',
        'subject_weekly' => 'Your weekly thread digest',
        'intro' => ':count new posts in the threads you follow.',
        'new_posts' => ':count new post|:count new posts',
        'open' => 'Open the thread',
    ],
];
