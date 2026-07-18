<?php

declare(strict_types=1);

namespace TestCases\Redis;

use Lsr\Caching\Cache;
use Lsr\Caching\Redis\RedisJournal;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Redis;

class RedisJournalTest extends TestCase
{
    private Redis $redis;
    private RedisJournal $journal;
    private string $namespace;

    protected function setUp(): void {
        $this->redis = new Redis();
        $redisHost = getenv('REDIS_HOST');
        if (!is_string($redisHost)) {
            $redisHost = '127.0.0.1';
        }
        $redisPort = getenv('REDIS_PORT');
        if (!is_string($redisPort)) {
            $redisPort = '6379';
        }
        $this->redis->connect($redisHost, (int) $redisPort);
        $this->namespace = 'lsr-cache-journal-test:' . hash('sha256', $this->name()) . ':';
        $this->journal = new RedisJournal($this->redis, $this->namespace);
    }

    protected function tearDown(): void {
        $this->journal->clean([Cache::All => true]);
        $this->redis->close();
    }

    public function testTagCleanRemovesAllDependenciesForSelectedKeys(): void {
        $this->journal->write('tag-clean-key', [
            Cache::Tags => ['tag-clean-trigger', 'tag-clean-related'],
            Cache::Priority => 10,
        ]);
        $this->journal->write('shared-tag-key', [
            Cache::Tags => ['tag-clean-related'],
            Cache::Priority => 20,
        ]);

        self::assertSame(
            ['tag-clean-key'],
            $this->journal->clean([Cache::Tags => ['tag-clean-trigger']]),
        );
        self::assertSame([], $this->redis->sMembers($this->tagKey('tag-clean-trigger')));
        self::assertSame(['shared-tag-key'], $this->redis->sMembers($this->tagKey('tag-clean-related')));
        self::assertSame([], $this->redis->sMembers($this->reverseTagKey('tag-clean-key')));
        self::assertFalse($this->redis->zScore($this->priorityKey(), 'tag-clean-key'));
        self::assertSame([], $this->journal->clean([Cache::Tags => ['tag-clean-trigger']]));
        self::assertSame(['shared-tag-key'], $this->journal->clean([Cache::Tags => ['tag-clean-related']]));
    }

    public function testPriorityCleanRemovesAllDependenciesForSelectedKeys(): void {
        $this->journal->write('priority-clean-key', [
            Cache::Tags => ['priority-clean-tag'],
            Cache::Priority => 10,
        ]);
        $this->journal->write('priority-survivor-key', [
            Cache::Tags => ['priority-clean-tag'],
            Cache::Priority => 20,
        ]);

        self::assertSame(
            ['priority-clean-key'],
            $this->journal->clean([Cache::Priority => 10]),
        );
        self::assertSame(['priority-survivor-key'], $this->redis->sMembers($this->tagKey('priority-clean-tag')));
        self::assertSame([], $this->redis->sMembers($this->reverseTagKey('priority-clean-key')));
        self::assertFalse($this->redis->zScore($this->priorityKey(), 'priority-clean-key'));
        self::assertSame([], $this->journal->clean([Cache::Priority => 10]));
        self::assertSame(['priority-survivor-key'], $this->journal->clean([Cache::Tags => ['priority-clean-tag']]));
    }

    public function testCleanAfterRedisConnectionAndObjectRecreation(): void {
        $this->journal->write('recreated-key', [
            Cache::Tags => ['recreated-tag'],
            Cache::Priority => 10,
        ]);
        $this->redis->close();

        $this->redis = $this->createRedisConnection();
        $this->journal = new RedisJournal($this->redis, $this->namespace);

        self::assertSame(['recreated-key'], $this->journal->clean([Cache::Tags => ['recreated-tag']]));
        self::assertSame([], $this->journal->clean([Cache::Priority => 10]));
    }

    public function testFullCleanOnlyRemovesSelectedJournalNamespace(): void {
        $otherNamespace = $this->namespace . 'other:';
        $otherJournal = new RedisJournal($this->redis, $otherNamespace);
        $this->journal->write('own-key', [Cache::Tags => ['shared-name']]);
        $otherJournal->write('other-key', [Cache::Tags => ['shared-name']]);

        $this->journal->clean([Cache::All => true]);

        self::assertSame([], $this->redis->sMembers($this->tagKey('shared-name')));
        self::assertSame(
            ['other-key'],
            $this->redis->sMembers($otherNamespace . 'journal:dependencies:tags:shared-name'),
        );
        $otherJournal->clean([Cache::All => true]);
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
        $this->assertEqualsCanonicalizing($data, $keys);
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
        $this->assertEqualsCanonicalizing(array_slice($data, 0, 3), $keys);
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

        $keys = $this->redis->sUnion($this->tagKey('tag1'));
        $this->assertEqualsCanonicalizing($data, $keys);
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

        $keys = $this->redis->zRangeByScore($this->priorityKey(), '0', '3');
        $this->assertEquals(array_slice($data, 0, 3), $keys);
    }

    private function createRedisConnection(): Redis {
        $redisHost = getenv('REDIS_HOST');
        if (!is_string($redisHost)) {
            $redisHost = '127.0.0.1';
        }
        $redisPort = getenv('REDIS_PORT');
        if (!is_string($redisPort)) {
            $redisPort = '6379';
        }

        $redis = new Redis();
        $redis->connect($redisHost, (int) $redisPort);
        return $redis;
    }

    private function tagKey(string $tag): string {
        return $this->namespace . 'journal:dependencies:tags:' . $tag;
    }

    private function reverseTagKey(string $key): string {
        return $this->namespace . 'journal:dependencies:reverseTags:' . $key;
    }

    private function priorityKey(): string {
        return $this->namespace . 'journal:dependencies:priority';
    }
}
