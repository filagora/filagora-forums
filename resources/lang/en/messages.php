<?php

declare(strict_types=1);

return [
    'navigation_groups' => [
        'forum' => 'Forum',
    ],

    'groups' => [
        'navigation_label' => 'Forum groups',
        'model_label' => 'forum group',
        'plural_model_label' => 'forum groups',
    ],

    'forums' => [
        'navigation_label' => 'Forums',
        'model_label' => 'forum',
        'plural_model_label' => 'forums',
        'table' => [
            'group' => 'Group',
            'entitlement' => 'Required entitlement',
            'reply_entitlement' => 'Reply entitlement',
        ],
        'form' => [
            'required_entitlement_scope' => 'Required entitlement',
            'required_entitlement_scope_help' => 'For "entitlement only" forums: holders of this entitlement can see the forum.',
            'reply_entitlement_scope' => 'Reply entitlement',
            'reply_entitlement_scope_help' => 'Holders of this entitlement can reply even where the posting policy would stop them.',
            'requires_thread_approval' => 'New threads need approval',
            'requires_thread_approval_help' => 'Threads wait for a moderator unless the author can bypass approval.',
            'requires_reply_approval' => 'Replies need approval',
            'requires_reply_approval_help' => 'Replies wait for a moderator unless the author can bypass approval.',
            'show_when_empty' => 'Show when empty',
            'show_when_empty_help' => 'List the forum to members even before it has any threads.',
        ],
    ],

    'threads' => [
        'navigation_label' => 'Threads',
        'model_label' => 'thread',
        'plural_model_label' => 'threads',
        'table' => ['author' => 'Author'],
    ],

    'posts' => [
        'navigation_label' => 'Posts',
        'model_label' => 'post',
        'plural_model_label' => 'posts',
    ],

    'reports' => [
        'navigation_label' => 'Post reports',
        'model_label' => 'post report',
        'plural_model_label' => 'post reports',
        'table' => [
            'post' => 'Post',
            'reporter' => 'Reporter',
        ],
    ],

    'queue' => [
        'navigation_label' => 'Moderation queue',
        'title' => 'Moderation queue',
        'pending_threads' => 'Threads waiting for approval',
        'pending_posts' => 'Replies waiting for approval',
        'reported_posts' => 'Reported replies',
        'count' => ':count waiting|:count waiting',
        'open_reports' => ':count open report|:count open reports',
        'empty' => 'Nothing waiting.',
    ],

    'widget' => [
        'waiting' => 'Waiting for approval',
        'waiting_description' => ':threads threads and :posts replies',
        'reported' => 'Reported replies',
        'reported_description' => ':count reply with open reports|:count replies with open reports',
    ],

    'actions' => [
        'approve' => 'Approve',
        'unapprove' => 'Send back for approval',
        'reject' => 'Reject',
        'lock' => 'Lock',
        'unlock' => 'Unlock',
        'pin' => 'Pin',
        'unpin' => 'Unpin',
        'rename' => 'Rename',
        'move' => 'Move to another forum',
        'destination_forum' => 'Destination forum',
        'delete_thread' => 'Delete thread',
        'delete_thread_warning' => 'This deletes the thread and every post in it, for good.',
        'delete_post' => 'Delete',
        'restore' => 'Restore',
        'edit' => 'Edit',
        'read' => 'Read',
        'reason' => 'Reason',
        'gone' => 'That item no longer exists.',
        'thread_approved' => 'Thread approved.',
        'thread_locked' => 'Thread locked.',
        'thread_unlocked' => 'Thread unlocked.',
        'thread_pinned' => 'Thread pinned.',
        'thread_unpinned' => 'Thread unpinned.',
        'thread_renamed' => 'Thread renamed.',
        'thread_moved' => 'Thread moved.',
        'thread_deleted' => 'Thread deleted.',
        'post_approved' => 'Post approved.',
        'post_unapproved' => 'Post sent back for approval.',
        'post_rejected' => 'Post rejected.',
        'post_deleted' => 'Post deleted.',
        'post_restored' => 'Post restored.',
        'post_edited' => 'Post updated.',
        'post_unchanged' => 'Nothing changed.',
        'resolve' => 'Resolve',
        'outcome' => 'Outcome',
        'note' => 'Resolution note',
        'dismiss' => 'Dismiss',
        'keep' => 'Keep the post',
        'remove_post' => 'Remove the post',
        'remove_post_and_thread' => 'Remove the post and its thread',
        'outcome_dismiss' => 'Not a violation.',
        'outcome_keep' => 'The post stays; your note is kept on the report.',
        'outcome_remove' => 'Soft-deletes the post.',
        'outcome_remove_thread' => 'The post is the only one in its thread, so the thread is deleted too.',
        'report_resolved' => 'Report resolved.',
    ],
];
