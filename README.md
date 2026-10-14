# Filagora Forums

Forum groups, forums, threads and posts for Filagora, with visibility and posting rules,
moderation, reports and a staff queue, as a Laravel 13 / Filament 5 plugin. Requires
[`filagora/filagora-core`](https://github.com/filagora/filagora-core) and
[`filagora/filagora-entitlements`](https://github.com/filagora/filagora-entitlements).

> The package is at `0.1`: the API may change between `0.x` minor versions. There is no
> frontend: it is models, services, events and staff screens, and you build the public pages.

## What it does

- **The seven tables** (`forum_groups`, `forums`, `threads`, `posts`, `post_reports`,
  `post_moderation_logs`, `thread_moderation_logs`), matching the shape of the forum they were
  extracted from index for index, including the generated `threads.last_activity_at` column, the
  FULLTEXT indexes on forum names, thread titles and post bodies, and the tuned listing
  indexes. They are built for forums with hundreds of thousands of threads.
- **Models and enums** (`Forum`, `ForumGroup`, `Thread`, `Post`, `PostReport`,
  `PostModerationLog`, `ThreadModerationLog`; forum visibility, posting policy, moderation
  status, report status). Every stored value is kept, so existing data loads. Model classes
  can be swapped under `filagora.models.<key>`.
- **A schema snapshot** so `filagora:adopt` (from core) can start the package on a database
  that already holds these tables. A site's own extra columns are declared with a core
  `SchemaExtension`.

- **The access rules** (`ForumAccess`): who can view a forum, open a thread, post and reply,
  from the forum's visibility (public, registered, staff only, entitlement only, paid only, paid
  or author, staff or author) and posting policy, the viewer's entitlements, and whether they
  have authored in the forum. It also says which forums a viewer sees in full, which only their
  own threads in, and gives query scopes for visible threads and posts.
- **Three staff abilities** (`filagora.forums.moderate`, `.view_unapproved`, `.bypass_approval`),
  each mapped to an entitlement key in `filagora-forums.gates`; holding any one makes a user
  staff. Remap them to your own keys in config.
- **Two extension points:** bind `PaidAccessCheck` to say what "paid" means for forums that name
  no entitlement (the default grants nobody), and `StaffPreview` if your frontend can switch staff
  privileges off to see the forum as a member does (the default is always on).

- **Counters kept right.** A forum holds `thread_count`, `post_count` and last-post fields, and a
  thread holds `reply_count` and last-post fields, so listings never count rows. They are updated
  incrementally as threads and posts change (a post counts when it and its thread are approved),
  and `filagora:forums:sync-stats` and `filagora:forums:sync-thread-stats` rebuild them from the
  data if they ever drift. A `ForumStatsChanged` event tells a frontend when to drop its caches.

- **Posting.** `Posting` starts threads, replies and edits posts, checking `ForumAccess` first
  and refusing with `PostingNotPermitted` and a reason (`PostingDenial`). A new thread creates its
  first post with it. Whether content waits for a moderator follows the forum's approval flags,
  the author's `filagora.forums.bypass_approval` entitlement and your `ContentModerationPolicy`;
  slugs are made unique; an identical post within 15 seconds is returned rather than duplicated;
  counters are kept right. Authors may edit their own posts within a window set by the
  `forum_post_edit_window_minutes` setting (default 30), and an edit shows as "edited" after
  `forum_post_edit_grace_period_minutes` (default 5). Events `ThreadCreated`, `PostCreated` and
  `PostEdited` let add-ons (subscriptions, mentions, notifications, attachments) hook in.
- **Two more extension points:** `ContentModerationPolicy` (users whose content is always
  moderated, and a first post awaiting review) and `PostSignatureResolver` (the signature copied
  onto new posts). Both do nothing by default.

- **Moderation.** For moderators (`filagora.forums.moderate`; anyone else gets
  `ModerationNotPermitted`): `ThreadModeration` locks, unlocks, pins, unpins, moves, renames,
  approves and permanently deletes threads; `PostModeration` soft-deletes (singly or up to 200
  at once), restores, approves, unapproves and rejects posts; `PostRelocation` moves posts to
  another thread and promotes posts into a new one, singly or in batches, and removes a thread
  the move emptied. Each action writes a row to the thread or post moderation log, re-derives
  the counters, and dispatches `ThreadModerated`, `PostModerated` or `ThreadDeleted`, which is
  where you notify authors or ban them. Undo is left to the frontend.
- **Reports.** `PostReports::report()` lets a member who can see an approved post report it
  once; `resolve()` closes every open report on the post at once as `dismiss`, `keep`, `remove`
  or `remove_thread` (the whole thread, if the post is all it holds) and dispatches
  `PostReportsResolved` with the reporters' ids.

- **Filament screens.** `FilagoraForumsPlugin` adds forum groups and forums (create and edit),
  threads, posts and post reports (browse, filter, and act through the moderation services, so
  every action is logged and counted), a **Moderation queue** page (threads and replies waiting
  for approval, reported replies one row each) and a `ModerationQueueStats` dashboard widget.
  Every screen needs the `filagora.forums.moderate` ability. Register it on the panel next to the
  core and entitlements plugins:

  ```php
  ->plugins([
      FilagoraCorePlugin::make(),
      FilagoraEntitlementsPlugin::make(),
      FilagoraForumsPlugin::make(),
  ])
  ```

  The moderation queue's navigation badge and the widget count what is waiting; add
  `ModerationQueueStats::class` to your dashboard if your panel lists widgets explicitly.

- **Site announcements** (banners). An editor publishes a message for a time window, to
  everyone, guests or signed-in users, with a severity, a priority, whether visitors can dismiss
  it and whether it keeps showing, and optional **targeting rules** (all must pass).
  `ActiveAnnouncements::forViewer($user, ['forum_id' => $id])` returns what to show one viewer
  (the shared pool is cached by id for 60 seconds and cleared on every save); `AnnouncementDismissals`
  records a dismissal (only for a dismissible announcement) or the first view of a
  non-persistent one, and lists a user's dismissed ids. The screen (**Announcements**, in the
  Forum group) needs its own ability, `filagora.forums.announcements`, which does not make
  anyone forum staff. The package has no routes, views or JavaScript: render the banners and
  add the two endpoints in your application, for example

  ```php
  Route::post('/announcements/{id}/dismiss', fn (Request $r, int $id, AnnouncementDismissals $d) =>
      response()->json(['dismissed' => $d->dismiss($r->user(), $id)]))->middleware('auth');
  ```

  Rules are classes registered on `TargetingRules`; it ships `entitlement`, `username`,
  `date_is`, `time_between`, `never_posted`, `post_count_between`, `not_posted_days` and
  `browsing_forum` (which reads `forum_id` from the context you pass). Implement `TargetingRule`
  (a type, a label, the form fields, and `matches()`) and `register()` it to add your own. The
  username and timezone columns the rules read are set in `filagora-forums.announcements`. The
  tables are `site_notifications` and `user_site_notification_dismissals`.

## Install

```bash
composer require filagora/filagora-forums
php artisan migrate
php artisan filagora:entitlements:sync   # creates the forum entitlement definitions
```

Register the plugin on your Filament panel, after the core and entitlements plugins:

```php
->plugin(\Filagora\Forums\FilagoraForumsPlugin::make())
```

To start from a database that already holds these tables, run `php artisan filagora:adopt`
instead of `migrate` (see `filagora/filagora-core`).

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
