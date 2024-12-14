<?php

declare(strict_types=1);

namespace TestCases\Redis;

use Lsr\Caching\Cache;
use Lsr\Caching\Redis\RedisJournal;
use Nette\Caching\Storages\Journal;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisJournalTest extends TestCase
{
    private Redis $redis;
    private Journal $journal;

    protected function setUp(): void {
        $this->redis = new Redis();
        $redisHost = getenv('REDIS_HOST');
        if (!is_string($redisHost)) {
            $redisHost = '127.0.0.1';
        }
        $this->redis->connect($redisHost);
        $this->redis->flushAll();
        $this->journal = new RedisJournal($this->redis);
    }

    #[Depends('testWrite')]
    public function testClean(): void {
        $data = [
            'testWriteClean1',
            'testWriteClean2',
            'testWriteClean3',
            'testWriteClean4',
        ];
        foreach ($data as $value) {
            $this->journal->write(
                $value,
                [
                    Cache::Tags => ['clean'],
                ]
            );
        }

        $keys = $this->journal->clean([Cache::Tags => ['clean']]);
        $this->assertEquals($data, $keys);
    }

    #[Depends('testWritePriority')]
    public function testCleanPriority(): void {
        $data = [
            'testWriteCleanPriority1',
            'testWriteCleanPriority2',
            'testWriteCleanPriority3',
            'testWriteCleanPriority4',
        ];
        foreach ($data as $key => $value) {
            $this->journal->write(
                $value,
                [
                    Cache::Priority => $key + 1,
                ]
            );
        }

        $keys = $this->journal->clean([Cache::Priority => 3]);
        $this->assertEquals(array_slice($data, 0, 3), $keys);
    }

    public function testWrite(): void {
        $data = [
            'testWrite1',
            'testWrite2',
            'testWrite3',
            'testWrite4',
        ];
        foreach ($data as $value) {
            $this->journal->write(
                $value,
                [
                    Cache::Tags => ['tag1', 'tag2'],
                ]
            );
        }

        $keys = $this->redis->sUnion('journal:dependencies:tags:tag1');
        $this->assertEquals($data, $keys);
    }

    public function testWritePriority(): void {
        $data = [
            'testWritePriority1',
            'testWritePriority2',
            'testWritePriority3',
            'testWritePriority4',
        ];
        foreach ($data as $key => $value) {
            $this->journal->write(
                $value,
                [
                    Cache::Priority => $key + 1,
                ]
            );
        }

        $keys = $this->redis->zRangeByScore('journal:dependencies:priority', '0', '3');
        $this->assertEquals(array_slice($data, 0, 3), $keys);
    }
}
