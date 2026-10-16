# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **Read tracking.** Tables `forum_reads` and `thread_reads` (a sixth migration; `filagora:adopt` now expects nineteen tables and records six migrations), models `forum_read` and `thread_read`, and the `ReadTracking` service: mark a thread read or unread (a hand-made unread flag beats every read time), mark forums or everything visible read, `isUnread()`, `unreadAmong()` for a page of threads, and `unreadThreads()` for a "what's new" list (bounded by `read_tracking.unread_max_lookback_days`; read times are checked with subqueries so any query can be passed in). The person's baseline is read from the user column named by `read_tracking.baseline_attribute`. The privacy export includes a person's read markers and erasure removes them.

## [0.5.0] - 2026-10-16

### Added

- **Search** with `filagora/filagora-search` (suggested, not required): `ThreadSearchIndex` (approved threads in active forums, with the forum name and the text of approved posts copied in, capped by `search.post_text_limit`; the filter fields are a `filters` group for `filagora:search:backfill`), `ForumSearch` (`threads()` with the viewer's visibility as an engine-neutral filter and a stale-hit check, `unapproved()`, `posts()`), `ForumSearchCriteria`, and `ThreadDatabaseSearch`, which answers the same query from the tables (FULLTEXT on MariaDB unless `search.fulltext` is off). Posts and forums keep thread documents current (`SearchRelations`, `SyncForumThreadDocuments`).

## [0.4.0] - 2026-10-15

### Added

- **Polls.** Tables `polls`, `poll_questions`, `poll_options`, `poll_votes` and `poll_vote_answers` (a third migration; `filagora:adopt` now expects fourteen tables and records three migrations), models (`poll`, `poll_question`, `poll_option`, `poll_vote`, `poll_vote_answer` in `filagora-forums.models`), enum `PollStatus` and the `Polls` service: moderators create a poll on a thread (one per thread), replace its questions while nobody has voted, and close or reopen it, each logged on the thread (`poll_created`, `poll_updated`, `poll_closed`, `poll_reopened`) and announced as `ThreadModerated`; people who can see the thread vote once, all questions together, locked once cast; `results()` and `canSeeResults()` (staff, voters, or anyone once closed). Limits: 20 questions, 20 options, prompt 500 and label 255 characters, no repeated option. A refusal is a `PollRefused` with a `PollRefusal` reason.
- **Reactions.** Table `reactions` (a fourth migration; `filagora:adopt` now expects fifteen tables and records four migrations; the site this package was extracted from also has a `legacy_reaction_id` column and unique key, which a profile package declares as an extension), model `reaction`, and the `Reactions` service: `toggle()` adds a reaction to a post the person can see or takes it back (refused for deleted or unapproved posts and unknown kinds), `counts()` and `madeBy()` answer for many posts at once. The kinds are `reactions.types` in the config (`like` by default); the kind is stored as text, so adding one needs no migration.
- **Subscriptions and digests.** Tables `thread_subscriptions` and `thread_subscription_digest_items` (a fifth migration; `filagora:adopt` now expects seventeen tables and records five migrations), models, enum `DeliveryMode`, contract `SubscriptionPreferences` (default `UserAttributePreferences` reads two user columns, configured under `subscriptions.user_attributes`) and the `Subscriptions` service: follow and unfollow, `followedAmong()`, `shouldFollowOnPost()`, `announce()` (a new approved post becomes a `SubscribersToNotify` event for followers who want it at once and a digest-queue row for daily or weekly ones; the author and those who turned updates off get nothing) and `sendDigests()` (one `ThreadDigestReady` event per person with unsent items, then marked sent). A listener follows a thread's author on `ThreadCreated`, follows a reply's author by preference and announces approved replies. Default emails (`ThreadUpdatedNotification`, `ThreadDigestNotification`; links through the named route `subscriptions.routes.thread`) can be switched off with `subscriptions.mail.enabled`. Command `filagora:forums:send-digest {daily|weekly} [--dry-run]`; retention rule `forums.digest_items` (365 days).
- `ForumsDataContributor` also exports a person's poll votes, reactions, subscriptions and digest queue, and erases the subscriptions and digest queue.

## [0.3.2] - 2026-10-14

### Added

- **Privacy contributions**, registered with core's registries, so the compliance package (or any host) can export, erase and prune without forums knowing it: `ForumsDataContributor` (export of a person's threads, posts and the reports they filed; erasure leaves what they wrote, attributed to the anonymised account) and retention rules for the thread and post moderation logs and for dismissed reports (config `retention`: 1095, 1095 and 90 days; zero keeps them). Nothing here needs `filagora-compliance`; without it nothing runs the rules.

## [0.3.1] - 2026-10-14

### Changed

- **`filagora-entitlements` is now optional.** Every access question (the staff abilities, a forum's entitlement keys, an announcement's `entitlement` rule) goes through core's `AccessChecker`; entitlements answers it when installed, a host can bind its own. Gates come from core's `GateAbilities`, the screens use core's `GatedByAbility`, the module now requires only `core`, and `filagora-entitlements` moves from `require` to `suggest`. Without it the access-key form fields are text inputs instead of entitlement pickers, and nothing is declared to the entitlement registry. Behaviour with entitlements installed is unchanged. Requires `filagora/filagora-core` `^0.2.1`.

## [0.3.0] - 2026-10-14

### Added

- **Site announcements.** Tables `site_notifications` and `user_site_notification_dismissals` (a second migration; `filagora:adopt` now expects nine tables and records two migrations), models `SiteNotification` and `UserSiteNotificationDismissal` (`site_notification`, `site_notification_dismissal` in `filagora-forums.models`), enums `AnnouncementStatus`, `AnnouncementAudience` and `AnnouncementSeverity`, services `ActiveAnnouncements` and `AnnouncementDismissals`, and the targeting registry `TargetingRules` with the `TargetingRule` contract and eight rules.
- Filament resource `SiteNotificationResource` (list, create, edit, delete; filters; the rule builder is driven by the registry) behind the new ability `filagora.forums.announcements`, which is not a staff ability.
- Config `filagora-forums.announcements` (`username_attribute`, `timezone_attribute`) and the lang file `announcements.php`.

## [0.2.0] - 2026-10-13

### Changed

- **Renamed to Filagora.** The Composer package is now `filagora/filagora-forums` (it was `zcwilt/forumkit-forums`) and the repository is `github.com/filagora/filagora-forums`. The PHP namespace is `Filagora\Forums\` (was `ForumKit\Forums\`), the config file is `config/filagora-forums.php`, the staff abilities and their default entitlement keys start with `filagora.forums.` (for example `filagora.forums.moderate`), the commands are `filagora:forums:sync-stats` and `filagora:forums:sync-thread-stats`, and the table prefix is `filagora_`. It requires `filagora/filagora-core` `^0.2` and `filagora/filagora-entitlements` `^0.2`. Nothing else changed: moving from 0.1 means renaming those names in your application, its config and its stored keys.


## [0.1.0] - 2026-10-12

First release.

- The seven forum tables (`forum_groups`, `forums`, `threads`, `posts`, `post_reports`, `post_moderation_logs`, `thread_moderation_logs`), matching the shape of the forum they were extracted from index for index, including the generated `threads.last_activity_at` column, the FULLTEXT indexes and the tuned listing indexes.
- Models, enums and a model-resolution helper (`forumkit-forums.models`, overridable under `forumkit.models`).
- A schema snapshot so `forumkit:adopt` can adopt an existing database; a site's extra columns are declared with a core `SchemaExtension`.
- Access rules (`ForumAccess`): who can view a forum, open a thread, post in a forum and reply to a thread, from the forum's visibility and posting policy, the viewer's entitlements, whether they authored in the forum, and the staff concept; which forums a viewer sees in full and in which only their own threads; and query scopes for visible threads and posts.
- Extension points: `PaidAccessCheck` (what "paid" means; the default grants nobody) and `StaffPreview` (lets a frontend switch staff privileges off; the default is always on).
- Three staff abilities, `forumkit.forums.moderate`, `forumkit.forums.view_unapproved` and `forumkit.forums.bypass_approval`, mapped to entitlement keys in `forumkit-forums.gates`; holding any one makes a user staff. The keys, and the keys set on forums, are created as entitlement definitions by `forumkit:entitlements:sync`.
- Forum and thread stats: `ForumStats` keeps each forum's `thread_count`, `post_count` and `last_post_*` right incrementally as threads and posts are created, approved, rejected, moved and deleted (observers on the thread and post models), `ThreadStats` rebuilds a thread's `reply_count` and last-post fields, and `StatsSyncScope` lets a multi-step operation suppress the observers and do one update of its own. The `ForumStatsChanged` event announces which forums' counters changed, so a frontend can drop its caches.
- Commands `forumkit:forums:sync-stats` and `forumkit:forums:sync-thread-stats`, the safety net that rebuilds the counters from the threads and posts.
- Posting: `Posting` is the checked way to start a thread, reply and edit a post. It asks `ForumAccess` first and refuses with `PostingNotPermitted` carrying a `PostingDenial` reason (forum not visible, thread not accessible, not allowed, thread locked, thread not approved, awaiting first review). Creating a thread makes its first post with it; whether a thread or reply waits for a moderator comes from `ApprovalRules` (the author's content always moderated, or the forum asks and the author cannot bypass); slugs are made unique (`hello`, `hello-2`); an identical thread or reply from the same author within 15 seconds is returned instead of created twice; thread and forum counters are kept right.
- Events `ThreadCreated`, `PostCreated` (a reply, or a thread's first post) and `PostEdited` for add-ons (subscriptions, mentions, notifications, attachments).
- Author editing within a window, with an "edited" marker after a grace period, from the settings `forum_post_edit_window_minutes` (30) and `forum_post_edit_grace_period_minutes` (5), declared with the live forum's keys and types. Moderators may edit at any time.
- Extension points `ContentModerationPolicy` (users whose content is always moderated; first post awaiting review) and `PostSignatureResolver` (the signature copied onto new posts). Both default to doing nothing.
- Moderation: `ThreadModeration` (lock, unlock, pin, unpin, move, rename, approve, delete), `PostModeration` (soft delete singly or in batches of up to 200, restore, approve, unapprove, reject, reject permanently) and `PostRelocation` (move and promote posts, singly or in batches, removing a thread the move empties and carrying its logs and reports along). Moderators only (`ModerationNotPermitted`); every action writes the moderation log and re-derives the counters. Events `ThreadModerated`, `PostModerated` and `ThreadDeleted`.
- Reports: `PostReports` reports a post (once per member, visible approved posts only) and resolves all open reports on a post as dismiss, keep, remove or remove thread. Events `PostReported` and `PostReportsResolved`.
- Filament screens: forum groups, forums, threads, posts and post reports; a Moderation queue page and a `ModerationQueueStats` widget; all behind the `forumkit.forums.moderate` ability, with moderation actions going through the services. `ForumVisibility`, `ForumPostingPolicy`, `ContentModerationStatus` and `PostReportStatus` gain `label()` and `options()`.
- Requires core `^0.1` (0.1.3 or later: settings definitions survive request scopes).
