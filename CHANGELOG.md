# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

- The seven forum tables (`forum_groups`, `forums`, `threads`, `posts`, `post_reports`, `post_moderation_logs`, `thread_moderation_logs`), matching the shape of the forum they were extracted from index for index, including the generated `threads.last_activity_at` column, the FULLTEXT indexes and the tuned listing indexes.
- Models, enums and a model-resolution helper (`forumkit-forums.models`, overridable under `forumkit.models`).
- A schema snapshot so `forumkit:adopt` can adopt an existing database; a site's extra columns are declared with a core `SchemaExtension`.
- Access rules (`ForumAccess`): who can view a forum, open a thread, post in a forum and reply to a thread, from the forum's visibility and posting policy, the viewer's entitlements, whether they authored in the forum, and the staff concept; which forums a viewer sees in full and in which only their own threads; and query scopes for visible threads and posts.
- Extension points: `PaidAccessCheck` (what "paid" means; the default grants nobody) and `StaffPreview` (lets a frontend switch staff privileges off; the default is always on).
- Three staff abilities, `forumkit.forums.moderate`, `forumkit.forums.view_unapproved` and `forumkit.forums.bypass_approval`, mapped to entitlement keys in `forumkit-forums.gates`; holding any one makes a user staff. The keys, and the keys set on forums, are created as entitlement definitions by `forumkit:entitlements:sync`.
- Forum and thread stats: `ForumStats` keeps each forum's `thread_count`, `post_count` and `last_post_*` right incrementally as threads and posts are created, approved, rejected, moved and deleted (observers on the thread and post models), `ThreadStats` rebuilds a thread's `reply_count` and last-post fields, and `StatsSyncScope` lets a multi-step operation suppress the observers and do one update of its own. The `ForumStatsChanged` event announces which forums' counters changed, so a frontend can drop its caches.
- Commands `forumkit:forums:sync-stats` and `forumkit:forums:sync-thread-stats`, the safety net that rebuilds the counters from the threads and posts.
