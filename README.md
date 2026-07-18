# LSR Cache

Cache storage integrations for the LSR framework.

## Redis storage namespaces

Use the same application-specific namespace for `RedisStorage` and `RedisJournal` when multiple
caches share one Redis instance:

```php
$journal = new RedisJournal($redis, 'application:cache:');
$storage = new RedisStorage($redis, 'application:cache:', $journal);
```

The namespace arguments are optional. Existing constructor calls without them remain supported.
Storage instances using the same storage prefix share one cache ownership index, so a full clean from
one of them cleans that shared cache namespace.

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

## Redis integration tests

The Redis tests use `REDIS_HOST` and `REDIS_PORT`, defaulting to `127.0.0.1:6379`:

```sh
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer test
```
