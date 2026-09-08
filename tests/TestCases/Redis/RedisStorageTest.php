<?php

declare(strict_types=1);

namespace TestCases\Lsr\Caching\Redis;

use Lsr\Caching\Cache;
use Lsr\Caching\Redis\RedisJournal;
use Lsr\Caching\Redis\RedisStorage;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisStorageTest extends TestCase
{
    private Redis $redis;
    private RedisJournal $journal;
    private RedisStorage $storage;
    private string $namespace;
    /** @var callable(mixed $value):string */
    private $serialize;

    /** @var callable(string $serialized):mixed */
    private $unserialize;

    protected function setUp(): void {
        if (extension_loaded('igbinary')) {
            $this->serialize = 'igbinary_serialize';
            $this->unserialize = 'igbinary_unserialize';
        } else {
            $this->serialize = 'serialize';
            $this->unserialize = static fn (string $serialized) => unserialize($serialized, ['allowed_classes' => true]);
        }

        $this->namespace = 'lsr-cache-storage-test:' . hash('sha256', $this->name()) . ':';
        $this->redis = $this->createRedisConnection();
        $this->journal = new RedisJournal($this->redis, $this->namespace);
        $this->storage = new RedisStorage(
            $this->redis,
            $this->namespace,
            $this->journal,
        );
    }

    protected function tearDown(): void {
        $this->storage->clean([Cache::All => true]);
        $this->redis->close();
    }

    public function test_bulk_load_preserves_values_for_associative_and_sparse_keys(): void {
        $cache = new Cache($this->storage);
        $cache->save('first', 'first value');
        $cache->save('second', 'second value');

        self::assertSame(
            ['first' => 'first value', 'second' => 'second value'],
            $cache->bulkLoad(['named' => 'first', 7 => 'second']),
        );
    }

    public function test_remove(): void {
        $value = ($this->serialize)(['data' => 'value']);
        $this->redis->set($this->storageKey('test-remove'), $value);

        $this->assertEquals($value, $this->redis->get($this->storageKey('test-remove')));
        $this->storage->remove('test-remove');
        $this->assertFalse($this->redis->get($this->storageKey('test-remove')));
    }

    public function test_clean_all(): void {
        $data = [
            'test-clean-1' => 'value1',
            'test-clean-2' => 'value2',
            'test-clean-3' => 'value3',
            'test-clean-4' => 'value4',
        ];
        foreach ($data as $k => $v) {
            $this->storage->write($k, $v, [Cache::Tags => ['full-clean']]);
        }
        $unrelatedKey = $this->namespace . 'unrelated';
        $otherDatabaseKey = $this->namespace . 'other-database';
        $this->redis->set($unrelatedKey, 'keep');
        $otherDatabase = $this->createRedisConnection();
        $otherDatabase->select(1);
        $otherDatabase->set($otherDatabaseKey, 'keep');

        $this->storage->clean([Cache::All => true]);

        foreach ($data as $k => $v) {
            $this->assertFalse($this->redis->get($this->storageKey($k)));
        }
        self::assertSame('keep', $this->redis->get($unrelatedKey));
        self::assertSame('keep', $otherDatabase->get($otherDatabaseKey));
        self::assertSame([], $this->journal->clean([Cache::Tags => ['full-clean']]));

        $this->redis->del($unrelatedKey);
        $otherDatabase->del($otherDatabaseKey);
        $otherDatabase->close();
    }

    public function test_clean_tags(): void {
        $data = [
            'test-clean-tags-1' => 'value1',
            'test-clean-tags-2' => 'value2',
            'test-clean-tags-3' => 'value3',
            'test-clean-tags-4' => 'value4',
        ];
        foreach ($data as $k => $v) {
            $this->storage->write($k, $v, [Cache::Tags => ['test-clean']]);
        }
        $data1 = [
            'test-unclean-tags-1' => 'value1',
            'test-unclean-tags-2' => 'value2',
            'test-unclean-tags-3' => 'value3',
        ];
        foreach ($data1 as $k => $v) {
            $this->storage->write($k, $v, [Cache::Tags => ['test-clean-1']]);
        }

        $this->storage->clean([Cache::Tags => ['test-clean']]);

        foreach ($data as $k => $v) {
            $this->assertFalse($this->redis->get($this->storageKey($k)));
        }
        foreach ($data1 as $k => $v) {
            $this->assertNotFalse($this->redis->get($this->storageKey($k)));
        }
    }

    public function test_read(): void {
        $this->storage->write('test-read', 'read', []);

        $data = $this->storage->read('test-read');
        $this->assertEquals('read', $data);
    }

    public function test_read_empty(): void {
        $this->assertNull($this->storage->read('test-read-invalid'));
    }

    public function test_write(): void {
        $this->storage->write('test', 'test', []);
        $data = $this->redis->get($this->storageKey('test'));
        $this->assertTrue(is_string($data));
        $read = ($this->unserialize)($data);
        $this->assertTrue(is_array($read));
        $this->assertTrue(isset($read['data']));
        $this->assertEquals('test', $read['data']);
    }

    public function test_write_tags(): void {
        $this->storage->write('test-write-tag', 'test', [Cache::Tags => ['test']]);
        $data = $this->redis->get($this->storageKey('test-write-tag'));
        $this->assertTrue(is_string($data));
        $read = ($this->unserialize)($data);
        $this->assertTrue(is_array($read));
        $this->assertTrue(isset($read['data']));
        $this->assertEquals('test', $read['data']);

        $keys = $this->journal->clean([Cache::Tags => ['test']]);
        $this->assertEqualsCanonicalizing([$this->storageKey('test-write-tag')], $keys);
    }

    public function test_write_expire(): void {
        $this->storage->write('test-expire', 'test', [Cache::Expire => 1]);
        $this->assertSame('test', $this->storage->read('test-expire'));

        $deadline = hrtime(true) + 2_000_000_000;
        do {
            usleep(10_000);
            $data = $this->storage->read('test-expire');
        } while ($data !== null && hrtime(true) < $deadline);

        $this->assertNull($data);
    }

    public function test_write_expire_sliding(): void {
        $this->storage->write('test-expire-sliding', 'test', [Cache::Expire => 2, Cache::Sliding => true]);
        $data = $this->storage->read('test-expire-sliding');
        $this->assertTrue(is_string($data));
        $this->assertEquals('test', $data);
        for ($i = 0; $i < 4; $i++) {
            sleep(1);
            $data = $this->storage->read('test-expire-sliding');
            $this->assertTrue(is_string($data));
            $this->assertEquals('test', $data);
        }
        for ($i = 0; $i < 2; $i++) {
            sleep(1);
            $data = $this->storage->bulkRead(['test-expire-sliding']);
            $this->assertTrue(is_array($data));
            $this->assertEquals(['test-expire-sliding' => 'test'], $data);
        }
        sleep(3);
        $this->assertNull($this->storage->read('test-expire-sliding'));
    }

    public function test_bulk_read(): void {
        $data = [
            'test-bread-1' => 'read1',
            'test-bread-2' => 'read2',
            'test-bread-3' => 'read3',
            'test-bread-4' => 'read4',
        ];
        foreach ($data as $k => $v) {
            $this->storage->write($k, $v, []);
        }

        $read = $this->storage->bulkRead(array_keys($data));
        $this->assertEquals($data, $read);
    }

    public function test_tag_clean_after_redis_connection_and_object_recreation(): void {
        $this->storage->write('recreated-storage-key', 'value', [Cache::Tags => ['recreated-storage-tag']]);
        $this->redis->close();

        $this->redis = $this->createRedisConnection();
        $this->journal = new RedisJournal($this->redis, $this->namespace);
        $this->storage = new RedisStorage($this->redis, $this->namespace, $this->journal);
        $this->storage->clean([Cache::Tags => ['recreated-storage-tag']]);

        self::assertNull($this->storage->read('recreated-storage-key'));
        self::assertSame([], $this->journal->clean([Cache::Tags => ['recreated-storage-tag']]));
    }

    private function createRedisConnection(): Redis {
        $redisHost = getenv('REDIS_HOST');
        if ( ! is_string($redisHost)) {
            $redisHost = '127.0.0.1';
        }
        $redisPort = getenv('REDIS_PORT');
        if ( ! is_string($redisPort)) {
            $redisPort = '6379';
        }

        $redis = new Redis();
        $redis->connect($redisHost, (int) $redisPort);
        return $redis;
    }

    private function storageKey(string $key): string {
        return urlencode($this->namespace . $key);
    }
}
