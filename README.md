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

## Still to come

The rules and screens, built in this order: who can view, open, post and reply (visibility and
posting policy, including entitlement-gated and author-only forums); thread and reply creation
with the forum and thread counters kept correct; moderation actions and report resolution, with
events other packages can hook into; the Filament resources and moderation queue.

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
