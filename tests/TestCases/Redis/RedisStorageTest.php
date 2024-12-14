<?php

declare(strict_types=1);

namespace TestCases\Lsr\Caching\Redis;

use Lsr\Caching\Cache;
use Lsr\Caching\Redis\RedisStorage;
use Nette\Caching\Storages\Journal;
use Nette\Caching\Storages\SQLiteJournal;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisStorageTest extends TestCase
{
    private Redis $redis;
    private Journal $journal;
    private RedisStorage $storage;
    /** @var callable(mixed $value):string */
    private $serialize;

    /** @var callable(string $serialized):mixed */
    private $unserialize;

    public function __construct(string $name) {
        if (extension_loaded('igbinary')) {
            $this->serialize = 'igbinary_serialize';
            $this->unserialize = 'igbinary_unserialize';
        } else {
            $this->serialize = 'serialize';
            $this->unserialize = static fn(string $serialized) => unserialize($serialized, ['allowed_classes' => true]);
        }
        parent::__construct($name);
    }

    public function testRemove(): void {
        $value = ($this->serialize)(['data' => 'value']);
        $this->redis->set('test-remove', $value);

        $this->assertEquals($value, $this->redis->get('test-remove'));
        $this->storage->remove('test-remove');
        $this->assertFalse($this->redis->get('test-remove'));
    }

    public function testCleanAll(): void {
        $data = [
            'test-clean-1' => 'value1',
            'test-clean-2' => 'value2',
            'test-clean-3' => 'value3',
            'test-clean-4' => 'value4',
        ];
        foreach ($data as $k => $v) {
            $this->redis->set($k, ($this->serialize)(['data' => $v]));
        }

        $this->storage->clean([Cache::All => true]);

        foreach ($data as $k => $v) {
            $this->assertFalse($this->redis->get($k));
        }
    }

    public function testCleanTags(): void {
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
            $this->assertFalse($this->redis->get($k));
        }
        foreach ($data1 as $k => $v) {
            $this->assertNotFalse($this->redis->get($k));
        }
    }

    public function testRead(): void {
        $this->redis->set(
            'test-read',
            ($this->serialize)(['data' => 'read']),
        );

        $data = $this->storage->read('test-read');
        $this->assertEquals('read', $data);
    }

    public function testReadEmpty(): void {
        $this->assertNull($this->storage->read('test-read-invalid'));
    }

    public function testWrite(): void {
        $this->storage->write('test', 'test', []);
        $data = $this->redis->get('test');
        $this->assertTrue(is_string($data));
        $read = ($this->unserialize)($data);
        $this->assertTrue(is_array($read));
        $this->assertTrue(isset($read['data']));
        $this->assertEquals('test', $read['data']);
    }

    public function testWriteTags(): void {
        $this->storage->write('test-write-tag', 'test', [Cache::Tags => ['test']]);
        $data = $this->redis->get('test-write-tag');
        $this->assertTrue(is_string($data));
        $read = ($this->unserialize)($data);
        $this->assertTrue(is_array($read));
        $this->assertTrue(isset($read['data']));
        $this->assertEquals('test', $read['data']);

        $keys = $this->journal->clean([Cache::Tags => ['test']]);
        $this->assertEquals(['test-write-tag'], $keys);
    }

    public function testWriteExpire(): void {
        $this->storage->write('test-expire', 'test', [Cache::Expire => 1]);
        $data = $this->redis->get('test-expire');
        $this->assertTrue(is_string($data));
        $read = ($this->unserialize)($data);
        $this->assertTrue(is_array($read));
        $this->assertTrue(isset($read['data']));
        $this->assertEquals('test', $read['data']);
        sleep(1);
        $this->assertFalse($this->redis->get('test-expire'));
    }

    public function testWriteExpireSliding(): void {
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

    public function testBulkRead(): void {
        $data = [
            'test-bread-1' => 'read1',
            'test-bread-2' => 'read2',
            'test-bread-3' => 'read3',
            'test-bread-4' => 'read4',
        ];
        foreach ($data as $k => $v) {
            $this->redis->set($k, ($this->serialize)(['data' => $v]));
        }

        $read = $this->storage->bulkRead(array_keys($data));
        $this->assertEquals($data, $read);
    }

    protected function setUp(): void {
        $journalDb = TMP_DIR . 'journal-redis.db';
        if (file_exists($journalDb)) {
            unlink($journalDb);
        }
        touch($journalDb);

        $this->journal = new SQLiteJournal($journalDb);
        $this->redis = new Redis();
        $redisHost = getenv('REDIS_HOST');
        if (!is_string($redisHost)) {
            $redisHost = '127.0.0.1';
        }
        $this->redis->connect($redisHost);
        $this->redis->flushAll();
        $this->storage = new RedisStorage(
            $this->redis,
            '',
            $this->journal,
        );
    }
}
