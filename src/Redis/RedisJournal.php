<?php

declare(strict_types=1);

namespace Lsr\Caching\Redis;

use Nette\Caching\Cache;
use Nette\Caching\Storages\Journal;
use Nette\NotSupportedException;
use Nette\SmartObject;
use Redis;

class RedisJournal implements Journal
{
    use SmartObject;

    private const string REVERSE_TAG_PREFIX = 'journal:dependencies:reverseTags:';
    private const string TAG_PREFIX = 'journal:dependencies:tags:';
    private const string PRIORITY_KEY = 'journal:dependencies:priority';

    public function __construct(
        private readonly Redis $redis,
        private readonly string $namespace = '',
    ) {
        if ( ! static::isAvailable()) {
            throw new NotSupportedException("PHP extension 'redis' is not loaded.");
        }
    }

    /**
     * Checks if Redis extension is available.
     */
    public static function isAvailable(): bool {
        return extension_loaded('redis');
    }

    /**
     * @inheritDoc
     *
     * @param  array{tags?:string[],priority?:float}  $dependencies
     */
    public function write(string $key, array $dependencies): void {
        $reverseTagKey = $this->getKey(self::REVERSE_TAG_PREFIX . $key);
        $tags = $this->redis->sMembers($reverseTagKey);
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $tagKey = $this->getKey(self::TAG_PREFIX . $tag);
                $this->redis->sRem($tagKey, $key);
                if ($this->redis->sCard($tagKey) === 0) {
                    $this->redis->del($tagKey);
                }
            }
        }
        $this->redis->del($reverseTagKey);
        $this->redis->zRem($this->getKey(self::PRIORITY_KEY), $key);

        if ( ! empty($dependencies[Cache::Tags])) {
            foreach ($dependencies[Cache::Tags] as $tag) {
                $this->redis->sAdd($this->getKey(self::TAG_PREFIX . $tag), $key);
            }
            $this->redis->sAddArray($reverseTagKey, $dependencies[Cache::Tags]);
        }

        if ( ! empty($dependencies[Cache::Priority])) {
            $this->redis->zAdd($this->getKey(self::PRIORITY_KEY), $dependencies[Cache::Priority], $key);
        }
    }

    /**
     * @inheritDoc
     *
     * @param  array{all?:bool,tags?:string[],priority?:float}  $conditions
     *
     * @return null|string[]
     */
    public function clean(array $conditions): ?array {
        if ( ! empty($conditions[Cache::All])) {
            $this->cleanAll();
            return null;
        }

        /** @var string[] $keys */
        $keys = [];
        if ( ! empty($conditions[Cache::Tags])) {
            $tags = array_map(
                fn (string $tag) => $this->getKey(self::TAG_PREFIX . $tag),
                ((array) $conditions[Cache::Tags]),
            );
            $keys = $this->redis->sUnion(...$tags);
            if ($keys === false) {
                $keys = [];
            }
        }
        assert(is_array($keys));

        if ( ! empty($conditions[Cache::Priority])) {
            $priorityKey = $this->getKey(self::PRIORITY_KEY);
            $priorityKeys = $this->redis->zRangeByScore($priorityKey, '0.0', (string) $conditions[Cache::Priority]);
            if ($priorityKeys === false) {
                $priorityKeys = [];
            }
            assert(is_array($priorityKeys));
            $keys = array_unique(array_merge($keys, $priorityKeys));
        }

        foreach ($keys as $key) {
            $reverseTagKey = $this->getKey(self::REVERSE_TAG_PREFIX . $key);
            $tags = $this->redis->sMembers($reverseTagKey);
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $tagKey = $this->getKey(self::TAG_PREFIX . $tag);
                    $this->redis->sRem($tagKey, $key);
                    if ($this->redis->sCard($tagKey) === 0) {
                        $this->redis->del($tagKey);
                    }
                }
            }

            $this->redis->del($reverseTagKey);
            $this->redis->zRem($this->getKey(self::PRIORITY_KEY), $key);
        }

        return $keys;
    }

    private function getKey(string $key): string {
        return $this->namespace . $key;
    }

    private function cleanAll(): void {
        $iterator = null;
        $namespacePattern = strtr(
            $this->namespace,
            [
                '\\' => '\\\\',
                '*'  => '\\*',
                '?'  => '\\?',
                '['  => '\\[',
                ']'  => '\\]',
            ],
        );
        $pattern = $namespacePattern . 'journal:dependencies:*';
        do {
            $keys = $this->redis->scan($iterator, $pattern, 100);
            if (is_array($keys) && ! empty($keys)) {
                $this->redis->del(...$keys);
            }
        } while ($iterator !== 0);
    }
}
