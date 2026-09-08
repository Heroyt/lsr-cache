<?php

declare(strict_types=1);

namespace Lsr\Caching\Redis;

use Nette\Caching\BulkReader;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\Journal;
use Nette\InvalidStateException;
use Nette\NotSupportedException;
use Nette\SmartObject;
use Redis;

class RedisStorage implements Storage, BulkReader
{
    use SmartObject;

    private const string KEY_INDEX_PREFIX = 'journal:storageKeys:';

    /** @internal cache structure */
    private const string META_DELTA = 'delta';
    private const string META_DATA = 'data';
    private const string META_CALLBACKS = 'callbacks';

    /** @var callable(mixed $value):string */
    private $serialize;

    /** @var callable(string $serialized):mixed */
    private $unserialize;

    private readonly string $keyIndex;

    public function __construct(
        private readonly Redis    $redis,
        private readonly string   $prefix = '',
        private readonly ?Journal $journal = null,
    ) {
        if ( ! static::isAvailable()) {
            throw new NotSupportedException("PHP extension 'redis' is not loaded.");
        }

        if (extension_loaded('igbinary')) {
            /** @phpstan-ignore assign.propertyType */
            $this->serialize = 'igbinary_serialize';
            $this->unserialize = 'igbinary_unserialize';
        } else {
            $this->serialize = 'serialize';
            $this->unserialize = static fn (string $serialized) => unserialize($serialized, ['allowed_classes' => true]);
        }

        $this->keyIndex = self::KEY_INDEX_PREFIX . hash('sha256', $this->prefix);
    }

    /**
     * Checks if Redis extension is available.
     */
    public static function isAvailable(): bool {
        return extension_loaded('redis');
    }

    public function getConnection(): Redis {
        return $this->redis;
    }

    /**
     * @inheritDoc
     */
    public function read(string $key): mixed {
        $key = urlencode($this->prefix . $key);

        /** @var string|false $meta */
        $meta = $this->redis->get($key);
        if ($meta === false) {
            return null;
        }

        /** @var array{data: mixed, delta?: int, callbacks?: list<array{0: callable, 1?: mixed, 2?: mixed}>} $data */
        $data = ($this->unserialize)($meta);

        // verify dependencies
        if ( ! empty($data[self::META_CALLBACKS]) && ! Cache::checkCallbacks($data[self::META_CALLBACKS])) {
            $this->redis->del($key);
            $this->redis->sRem($this->keyIndex, $key);
            return null;
        }

        if ( ! empty($data[self::META_DELTA])) {
            $this->redis->setex($key, $data[self::META_DELTA], $meta);
        }

        return $data[self::META_DATA];
    }

    /**
     * @inheritDoc
     */
    public function lock(string $key): void {
    }

    /**
     * @inheritDoc
     *
     * @param  array{items?: string[], cache?: string, callbacks?: list<array{0: callable, 1?: mixed, 2?: mixed}>,
     *   tags?: string[], priority?: int, sliding?: bool, files?: string[]|string,expire?:string|numeric}  $dependencies
     */
    public function write(string $key, mixed $data, array $dependencies): void {
        if (isset($dependencies[Cache::Items])) {
            throw new NotSupportedException('Dependent items are not supported by RedisStorage.');
        }
        $key = urlencode($this->prefix . $key);
        $meta = [
            self::META_DATA => $data,
        ];

        $expire = 0;
        if (isset($dependencies[Cache::Expire])) {
            $expire = (int) $dependencies[Cache::Expire];
            if ( ! empty($dependencies[Cache::Sliding])) {
                $meta[self::META_DELTA] = $expire; // sliding time
            }
        }

        if (isset($dependencies[Cache::Callbacks])) {
            $meta[self::META_CALLBACKS] = $dependencies[Cache::Callbacks];
        }

        if (isset($dependencies[Cache::Tags]) || isset($dependencies[Cache::Priority])) {
            if ( ! $this->journal) {
                throw new InvalidStateException('CacheJournal has not been provided.');
            }

            $this->journal->write($key, $dependencies);
        }

        if ($expire > 0) {
            $this->redis->setex($key, $expire, ($this->serialize)($meta));
        } else {
            $this->redis->set($key, ($this->serialize)($meta));
        }
        $this->redis->sAdd($this->keyIndex, $key);
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): void {
        $key = urlencode($this->prefix . $key);
        $this->redis->del($key);
        $this->redis->sRem($this->keyIndex, $key);
    }

    /**
     * @inheritDoc
     *
     * A full clean removes only values tracked for this storage prefix and metadata owned by its journal.
     * It never flushes unrelated Redis data.
     *
     * @param  array{all?: bool, tags?: string[], priority?: float}  $conditions
     */
    public function clean(array $conditions): void {
        if ( ! empty($conditions[Cache::All])) {
            $keys = $this->redis->sMembers($this->keyIndex);
            if (is_array($keys) && ! empty($keys)) {
                $this->redis->del(...$keys);
            }
            $this->redis->del($this->keyIndex);
            $this->journal?->clean($conditions);
            return;
        }

        if ($this->journal) {
            $keys = $this->journal->clean($conditions);
            if ($keys) {
                $this->redis->del(...$keys);
                $this->redis->sRem($this->keyIndex, ...$keys);
            }
        }
    }

    /**
     * Reads from cache in bulk.
     *
     * @param  string[]  $keys
     *
     * @return array<string, mixed> key => value pairs, missing items are omitted
     */
    public function bulkRead(array $keys): array {
        $prefixedKeys = array_map(fn ($key) => urlencode($this->prefix . $key), $keys);
        $keys = array_combine($prefixedKeys, $keys);
        /** @var array<string,string> $metas */
        $metas = $this->redis->mGet($prefixedKeys);
        $result = [];
        $deleteKeys = [];
        foreach ($metas as $key => $meta) {
            $prefixedKey = $prefixedKeys[$key];
            /** @var array{data: mixed, delta?: int, callbacks?: list<array{0: callable, 1?: mixed, 2?: mixed}>} $data */
            $data = ($this->unserialize)($meta);
            if ( ! empty($data[self::META_CALLBACKS]) && ! Cache::checkCallbacks($data[self::META_CALLBACKS])) {
                $deleteKeys[] = $prefixedKey;
            } else {
                $result[$keys[$prefixedKey]] = $data[self::META_DATA];
            }

            if ( ! empty($data[self::META_DELTA])) {
                $this->redis->setex($prefixedKey, $data[self::META_DELTA], $meta);
            }
        }

        if ( ! empty($deleteKeys)) {
            $this->redis->del(...$deleteKeys);
            $this->redis->sRem($this->keyIndex, ...$deleteKeys);
        }

        return $result;
    }
}
