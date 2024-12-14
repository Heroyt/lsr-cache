<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Cache;

trait CacheTestingTrait
{
    protected int $callCount = 0;
    protected readonly Cache $cache;

    public function testSaveLoad(): void {
        $key = 'test-key';
        $value = 'test-value';

        // Test loading a non-existent key (should return null)
        $this->assertNull($this->cache->load($key), 'Loading a non-existent key should return null.');

        // Save an item
        $this->cache->save($key, $value);

        // Load it back
        $loaded = $this->cache->load($key);
        $this->assertSame($value, $loaded, 'The cached value should match the saved value.');

        // Overwrite with a different value
        $newValue = 'new-value';
        $this->cache->save($key, $newValue);
        $loaded = $this->cache->load($key);
        $this->assertSame($newValue, $loaded, 'The cached value should have been updated.');
    }

    public function testRemove(): void {
        $key = 'removable-key';
        $value = 'removable-value';

        $this->cache->save($key, $value);
        $this->assertSame($value, $this->cache->load($key));

        // Remove the item
        $this->cache->remove($key);

        // Confirm removal
        $this->assertNull($this->cache->load($key), 'After removing, loading should return null.');
    }

    public function testClean(): void {
        $keyWithTags = 'tagged-key';
        $value = 'tagged-value';

        // Save an item with tags
        $this->cache->save($keyWithTags, $value, [
            Cache::Tags => ['category', 'expensive']
        ]);

        $this->assertSame($value, $this->cache->load($keyWithTags), 'The tagged item should be retrievable before cleaning.');

        // Clean based on a tag
        $this->cache->clean([Cache::Tags => ['category']]);

        $this->assertNull($this->cache->load($keyWithTags), 'The tagged item should be removed after cleaning by tag.');
    }

    public function testBulkLoad(): void {
        $items = [
            'bLoad-key1' => 'bLoad-value1',
            'bLoad-key2' => 'bLoad-value2',
            'bLoad-key3' => 'bLoad-value3'
        ];

        // Save items individually first
        foreach ($items as $k => $v) {
            $this->cache->save($k, $v);
        }

        // Bulk load them
        $loaded = $this->cache->bulkLoad(array_keys($items));
        $this->assertCount(count($items), $loaded, 'Loaded array should have all requested keys.');
        foreach ($items as $k => $v) {
            $this->assertSame($v, $loaded[$k], "The bulk loaded value for $k should match the saved value.");
        }

        // Test bulk loading with a missing key
        $loadedWithMissing = $this->cache->bulkLoad(['bLoad-key1', 'missing-key']);
        $this->assertSame($items['bLoad-key1'], $loadedWithMissing['bLoad-key1']);
        $this->assertNull($loadedWithMissing['missing-key'], 'Missing keys should return null in bulk load.');
    }

    public function testCall(): void {
        $this->callCount = 0;

        // Calling the same function multiple times should produce cached result after first call
        $result1 = $this->cache->call([$this, 'callFunc']);
        $result2 = $this->cache->call([$this, 'callFunc']);

        $this->assertSame('call-result', $result1);
        $this->assertSame('call-result', $result2);

        // Verify that the function was only called once due to caching
        $this->assertSame(1, $this->callCount, 'The function should only have been called once due to caching.');
    }

    public function testGetCalls(): void {
        $start = $this->cache->getCalls();

        $this->cache->load('key1');
        $this->cache->load('key2');
        $this->cache->load('key3');

        $calls = $this->cache->getCalls() - $start;
        $this->assertSame(3, $calls);
    }

    public function testBulkSave(): void {
        $items = [
            'bulk-key1' => 'bulk-value1',
            'bulk-key2' => 'bulk-value2',
            'bulk-key3' => 'bulk-value3'
        ];

        // Bulk save items
        $this->cache->bulkSave($items);

        // Verify that all items are present
        foreach ($items as $k => $v) {
            $this->assertSame($v, $this->cache->load($k), "The item $k should match its bulk saved value.");
        }
    }

    public function testWrap(): void {
        $this->callCount = 0;

        $wrapped = $this->cache->wrap([$this, 'wrapFunc']);

        $result1 = $wrapped(3, 4);
        $result2 = $wrapped(3, 4); // should be cached

        $this->assertSame(7, $result1);
        $this->assertSame(7, $result2);

        // Function should have been executed only once
        $this->assertSame(1, $this->callCount, 'Wrap should cache subsequent calls with the same arguments.');
    }

    public function wrapFunc(int $x, int $y): int {
        $this->callCount++;
        return $x + $y;
    }

    public function callFunc(): string {
        $this->callCount++;
        return 'call-result';
    }

    public function testBulkLoadEmpty(): void {
        $loaded = $this->cache->bulkLoad([]);
        $this->assertCount(0, $loaded);
    }

    public function testLoadWithGenerator(): void {
        $val = uniqid('', true);
        $load = $this->cache->load('gen-1', static fn() => $val);
        $this->assertSame($val, $load);
        $load = $this->cache->load('gen-1', static fn() => 'different value');
        $this->assertSame($val, $load);
    }

    public function testBulkLoadWithGenerator(): void {
        $val = uniqid('', true);
        $load = $this->cache->bulkLoad(['gen-1'], static fn() => $val);
        $this->assertTrue(isset($load['gen-1']));
        $this->assertSame($val, $load['gen-1']);
        $load = $this->cache->bulkLoad(['gen-1'], static fn() => 'different value');
        $this->assertTrue(isset($load['gen-1']));
        $this->assertSame($val, $load['gen-1']);
    }
}
