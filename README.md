# ForumKit Forums

Forum groups, forums, threads and posts for ForumKit, with visibility and posting rules,
moderation, reports and a staff queue, as a Laravel 13 / Filament 5 plugin. Requires
[`zcwilt/forumkit-core`](https://github.com/zcwilt/forumkit-core) and
[`zcwilt/forumkit-entitlements`](https://github.com/zcwilt/forumkit-entitlements).

> **In development: not released and not on Packagist yet**, so it cannot be installed with
> Composer. There is no frontend: the finished package will be models, services, events and
> staff screens.

## What exists so far

- **The seven tables** (`forum_groups`, `forums`, `threads`, `posts`, `post_reports`,
  `post_moderation_logs`, `thread_moderation_logs`), matching the shape of the forum they were
  extracted from index for index, including the generated `threads.last_activity_at` column, the
  FULLTEXT indexes on forum names, thread titles and post bodies, and the tuned listing
  indexes. They are built for forums with hundreds of thousands of threads.
- **Models and enums** (`Forum`, `ForumGroup`, `Thread`, `Post`, `PostReport`,
  `PostModerationLog`, `ThreadModerationLog`; forum visibility, posting policy, moderation
  status, report status). Every stored value is kept, so existing data loads. Model classes
  can be swapped under `forumkit.models.<key>`.
- **A schema snapshot** so `forumkit:adopt` (from core) can start the package on a database
  that already holds these tables. A site's own extra columns are declared with a core
  `SchemaExtension`.

- **The access rules** (`ForumAccess`): who can view a forum, open a thread, post and reply,
  from the forum's visibility (public, registered, staff only, entitlement only, paid only, paid
  or author, staff or author) and posting policy, the viewer's entitlements, and whether they
  have authored in the forum. It also says which forums a viewer sees in full, which only their
  own threads in, and gives query scopes for visible threads and posts.
- **Three staff abilities** (`forumkit.forums.moderate`, `.view_unapproved`, `.bypass_approval`),
  each mapped to an entitlement key in `forumkit-forums.gates`; holding any one makes a user
  staff. Remap them to your own keys in config.
- **Two extension points:** bind `PaidAccessCheck` to say what "paid" means for forums that name
  no entitlement (the default grants nobody), and `StaffPreview` if your frontend can switch staff
  privileges off to see the forum as a member does (the default is always on).

- **Counters kept right.** A forum holds `thread_count`, `post_count` and last-post fields, and a
  thread holds `reply_count` and last-post fields, so listings never count rows. They are updated
  incrementally as threads and posts change (a post counts when it and its thread are approved),
  and `forumkit:forums:sync-stats` and `forumkit:forums:sync-thread-stats` rebuild them from the
  data if they ever drift. A `ForumStatsChanged` event tells a frontend when to drop its caches.

## Still to come

Thread and reply creation (the approval rule, unique slugs, double-submit protection); moderation actions
and report resolution, with events other packages can hook into; the Filament resources and the
moderation queue.

## Install

Once released:

```bash
composer require zcwilt/forumkit-forums
php artisan migrate
```

```php
->plugin(\ForumKit\Forums\ForumKitForumsPlugin::make())
```

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
