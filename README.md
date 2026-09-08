# LSR Cache

`lsr/caching` provides `Lsr\Caching\Cache`, a Nette Cache wrapper with hit/miss statistics and lifecycle hooks, Redis storage and journal implementations, Nette DI integration and an optional console command.

## Requirements

- PHP `>=8.4` and `ext-redis` (required by Composer even when using file storage).
- Nette Caching `^3.3`, Nette DI `^3.2`, Tracy `^2.10` and `lsr/helpers` `^0.3` (installed by Composer).
- A reachable Redis/KeyDB server when using Redis storage. `ext-igbinary` is optional; the Redis implementation uses it when available and otherwise uses PHP serialization.
- An absolute, writable cache directory for the file-storage DI extension. `ext-pdo_sqlite` enables its SQLite journal.
- Symfony Console is needed for the optional command; `lsr/console` is suggested for framework command discovery.

## Installation

```sh
composer require lsr/caching
```

### Upgrading from the former package name

Replace direct `lsr/cache` requirements with `lsr/caching` and update the application's Composer lock file once the renamed release is available. The package declares `"replace": {"lsr/cache": "self.version"}` so older dependencies can continue requiring the equivalent version under its former name. Require `lsr/caching` explicitly; this declaration is not an automatic package redirect.

PHP namespaces and cache APIs remain `Lsr\Caching\…`. Composer installs the renamed package under `vendor/lsr/caching`; update any hard-coded paths. The source repository remains `Heroyt/lsr-cache`.

## File-storage integration

In an existing Nette DI application's configuration:

```neon
extensions:
    cache: Lsr\Caching\DI\CacheExtension

cache:
    cacheDir: /var/cache/example
    namespace: example
    debug: false
    commands: true
```

Choose an application-owned directory writable by the process compiling the container and by the runtime. [`CacheExtension`](src/DI/CacheExtension.php) creates a `FileStorage`, the cache service and a Tracy panel service. It also registers a SQLite journal when `pdo_sqlite` is available. The extension does not automatically configure Redis; construct/register the Redis services separately as below.

[`Cache`](src/Cache.php) accepts any Nette `Storage`. Its `load()` can generate missing values, and `bulkLoad()` uses bulk reads when the storage supports them. Lifecycle integrations attach through `setLifecycleHook()`.

## Redis storage namespaces

Use the same application-specific namespace for `RedisStorage` and `RedisJournal` when multiple
caches share one Redis instance:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Lsr\Caching\Cache;
use Lsr\Caching\Redis\RedisJournal;
use Lsr\Caching\Redis\RedisStorage;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379); // Configure the connection for your environment.

$journal = new RedisJournal($redis, 'application:cache:');
$storage = new RedisStorage($redis, 'application:cache:', $journal);
$cache = new Cache($storage);

$cache->save('greeting', 'Hello', [
    Cache::Expire => '10 minutes',
    Cache::Tags => ['greetings'],
]);
$greeting = $cache->load('greeting');
```

The namespace arguments are optional. Existing constructor calls without them remain supported.
Storage instances using the same storage prefix share one cache ownership index, so a full clean from
one of them cleans that shared cache namespace.

These are storage and journal namespaces, not merely the optional namespace passed to `Cache`.
See [`RedisStorage`](src/Redis/RedisStorage.php) and [`RedisJournal`](src/Redis/RedisJournal.php) for the storage-level ownership contract.

## Full-clean safety contract

`RedisStorage::clean([Cache::All => true])` deletes only cache values tracked for the storage prefix
and metadata owned by the configured journal namespace. It does not call `FLUSHALL`, switch logical
databases, or delete unrelated Redis keys. This makes a shared Redis/KeyDB instance safe when each
cache uses a distinct storage prefix and matching journal namespace.

Cache values written before version 0.3.3 were not added to an ownership index. After upgrading,
those legacy values cannot be distinguished safely from unrelated data when the storage prefix is
empty, and a full clean therefore leaves them in place. If they must be purged, remove them once
during a maintenance window using application-specific knowledge or clear a genuinely dedicated
Redis database. Do not use `FLUSHALL` on a shared instance.

## Console command

When Symfony Console is installed, `CacheExtension` registers `cache:clean` and its
`cache:clear` alias as a DI command service. Use `--tag=<tag>` to clean only cache
entries with that tag.

`lsr/console` discovers every Symfony `Command` service in the compiled container,
so other packages can use the same ownership pattern without changing `lsr/console`.
Set `commands: false` in the cache extension configuration to disable registration.

## Redis integration tests

The Redis tests use `REDIS_HOST` and `REDIS_PORT`, defaulting to `127.0.0.1:6379`:

```sh
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer test
```

Use a development Redis instance; the storage safety test also writes a namespaced key in logical database 1. Avoid concurrent test runs against the same instance because test namespaces are deterministic.

## Development

CI runs the complete suite on PHP 8.4 and 8.5 using Redis 7 and `pdo_sqlite`. Install the PHP extensions listed in [.github/workflows/ci.yml](.github/workflows/ci.yml), provide the development Redis instance described above, then run:

```sh
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

The bootstrap creates `tests/tmp/`; the checkout must be writable. `composer cs:fix` applies coding-style fixes (`composer cbf` is an alias). `composer test` enables Xdebug coverage mode; coverage reports need a compatible coverage driver. Configuration is in [`phpunit.xml`](phpunit.xml), [`phpstan.neon`](phpstan.neon) and [`.php-cs-fixer.php`](.php-cs-fixer.php).

## AI coding assistance

See [LSR Skills](https://github.com/Heroyt/lsr-skills) for AI agent skills for working with the LSR framework.

## License

Licensed under the [MIT License](LICENSE).
