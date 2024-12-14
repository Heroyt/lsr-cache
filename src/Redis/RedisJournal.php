<?php

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
        private readonly Redis $redis
    ) {
        if (!static::isAvailable()) {
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
        if (!empty($dependencies[Cache::Tags])) {
            $reverseTagKey = $this::REVERSE_TAG_PREFIX . $key;
            $tags = $this->redis->sMembers($reverseTagKey);
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $this->redis->sRem($this::TAG_PREFIX . $tag, $key);
                }
            }

            foreach ($dependencies[Cache::Tags] as $tag) {
                $this->redis->sAdd(self::TAG_PREFIX . $tag, $key);
            }
            $this->redis->sAddArray($reverseTagKey, $dependencies[Cache::Tags]);
        }

        if (!empty($dependencies[Cache::Priority])) {
            $this->redis->zAdd(self::PRIORITY_KEY, $dependencies[Cache::Priority], $key);
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
        if (!empty($conditions[Cache::All])) {
            return null;
        }

        /** @var string[] $keys */
        $keys = [];
        if (!empty($conditions[Cache::Tags])) {
            $tags = array_map(fn(string $tag) => $this::TAG_PREFIX . $tag, ((array) $conditions[Cache::Tags]));
            $keys = $this->redis->sUnion(...$tags);
            if ($keys === false) {
                $keys = [];
            }
        }
        assert(is_array($keys));

        if (!empty($conditions[Cache::Priority])) {
            $priorityKeys = $this->redis->zRangeByScore(self::PRIORITY_KEY, '0.0', (string) $conditions[Cache::Priority]);
            if ($priorityKeys === false) {
                $priorityKeys = [];
            }
            assert(is_array($priorityKeys));
            $keys = array_unique(array_merge($keys, $priorityKeys));
            $this->redis->zRemRangeByScore(self::PRIORITY_KEY, '0.0', (string) $conditions[Cache::Priority]);
        }

        $allTagsKeys = array_map(fn(string $key) => $this::REVERSE_TAG_PREFIX . $key, $keys);
        if (!empty($allTagsKeys)) {
            $allTags = $this->redis->sUnion(...array_map(fn(string $tag) => $this::TAG_PREFIX . $tag, $allTagsKeys));
            if (is_array($allTags) && !empty($allTags)) {
                $this->redis->del(...$allTags);
            }
        }

        return $keys;
    }
}
