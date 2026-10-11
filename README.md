# ForumKit Forums

Forum groups, forums, threads and posts for ForumKit, with visibility and posting rules,
moderation, reports and a staff queue, as a Laravel 13 / Filament 5 plugin. Requires
[`zencart/forumkit-core`](https://github.com/zcwilt/forumkit-core) and
[`zencart/forumkit-entitlements`](https://github.com/zcwilt/forumkit-entitlements).

> Work in progress: `0.x`, nothing released yet. There is no frontend: this package is the
> models, services, events and staff screens.

## Install

```bash
composer require zencart/forumkit-forums
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
