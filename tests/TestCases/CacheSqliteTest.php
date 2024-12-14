<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Cache;
use Nette\Caching\Storages\SQLiteStorage;
use PHPUnit\Framework\TestCase;

class CacheSqliteTest extends TestCase
{
    use CacheTestingTrait;

    protected function setUp(): void {
        // Recreate the cache DB
        $cacheDb = TMP_DIR . 'cache.db';
        if (file_exists($cacheDb)) {
            unlink($cacheDb);
        }
        touch($cacheDb);

        $this->cache = new Cache(
            new SQLiteStorage($cacheDb),
        );
    }
}
