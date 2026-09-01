<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Cache;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Caching\Storages\SQLiteStorage;
use PHPUnit\Framework\TestCase;

final class CacheLifecycleTest extends TestCase
{
    public function testLoadReportsGeneratedValueAndSubsequentHit(): void {
        $hook = new RecordingCacheLifecycleHook();
        $cache = (new Cache(new MemoryStorage()))->setLifecycleHook($hook);

        self::assertSame('value', $cache->load('private-key', static fn(): string => 'value'));
        self::assertSame('value', $cache->load('private-key'));

        self::assertSame([
            ['operation' => 'load', 'itemCount' => 1],
            ['operation' => 'load', 'itemCount' => 1],
        ], $hook->begins);
        self::assertSame([
            ['hits' => 0, 'misses' => 1, 'generated' => 1],
            ['hits' => 1, 'misses' => 0, 'generated' => 0],
        ], $hook->completions);
    }

    public function testBulkLoadReportsGeneratedValuesAndSubsequentHits(): void {
        $hook = new RecordingCacheLifecycleHook();
        $cache = (new Cache(new SQLiteStorage(':memory:')))->setLifecycleHook($hook);

        self::assertSame(
            ['first' => 'first-value', 'second' => 'second-value'],
            $cache->bulkLoad(
                ['first', 'second'],
                static fn(string $key): string => $key . '-value',
            ),
        );
        self::assertSame(
            ['first' => 'first-value', 'second' => 'second-value'],
            $cache->bulkLoad(['first', 'second']),
        );

        self::assertSame([
            ['operation' => 'bulk_load', 'itemCount' => 2],
            ['operation' => 'bulk_load', 'itemCount' => 2],
        ], $hook->begins);
        self::assertSame([
            ['hits' => 0, 'misses' => 2, 'generated' => 2],
            ['hits' => 2, 'misses' => 0, 'generated' => 0],
        ], $hook->completions);
    }

    public function testHookFailureDoesNotAffectCacheResult(): void {
        $hook = new RecordingCacheLifecycleHook();
        $hook->failBegin = true;
        $cache = (new Cache(new MemoryStorage()))->setLifecycleHook($hook);

        self::assertSame('value', $cache->load('key', static fn(): string => 'value'));
    }
}
