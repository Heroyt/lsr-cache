<?php

namespace Lsr\Caching;

use Nette\Caching\BulkReader;
use Nette\Caching\Storage;
use Nette\InvalidArgumentException;
use Throwable;

/**
 * Wrapper over Nette caching class adding statistics information
 *
 * @phpstan-type CacheDependencies array{
 *     priority?:int,
 *     expire?:non-empty-string|int,
 *     sliding?:bool,
 *     tags?:non-empty-string[],
 *     files?:non-empty-string[],
 *     items?:non-empty-string[],
 *     consts?:non-empty-string[],
 *     callbacks?:callable[],
 *     namespaces?:non-empty-string[],
 *     }
 */
class Cache extends \Nette\Caching\Cache
{
    public static int $hit = 0;
    public static int $miss = 0;
    /** @var array<string, array{0:int, 1:int}> */
    public static array $loadedKeys = [];

    public function __construct(
        Storage        $storage,
        ?string        $namespace = null,
        protected bool $debug = true,
    ) {
        parent::__construct($storage, $namespace);
    }

    /**
     * Reads multiple items from the cache.
     *
     * @template T
     *
     * @param  string[]  $keys
     * @param null|callable(string $key, CacheDependencies|null &$dependencies=):T $generator
     *
     * @return array<string,T>
     */
    public function bulkLoad(array $keys, ?callable $generator = null): array {
        if (count($keys) === 0) {
            return [];
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (!array_all($keys, static fn($key) => is_scalar($key))) {
            throw new InvalidArgumentException('Only scalar keys are allowed in bulkLoad()');
        }

        $result = [];
        if (!$this->getStorage() instanceof BulkReader) {
            foreach ($keys as $key) {
                $result[$key] = $this->load(
                    $key,
                    $generator !== null ? static fn (?array &$dependencies = null) => $generator($key, $dependencies) : null
                );
            }

            return $result;
        }

        $storageKeys = array_map([$this, 'generateKey'], $keys);
        $cacheData = $this->getStorage()->bulkRead($storageKeys);
        foreach ($keys as $i => $key) {
            $storageKey = $storageKeys[$i];
            if (isset($cacheData[$storageKey])) {
                $this->logLoadedKey($key);
                self::$hit++;
                $result[$key] = $cacheData[$storageKey];
            } elseif ($generator) {
                $this->logLoadedKey($key, true);
                self::$miss++;
                $result[$key] = $this->load(
                    $key,
                    fn (?array &$dependencies = null) => $generator($key, $dependencies)
                );
            } else {
                $this->logLoadedKey($key, true);
                self::$miss++;
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * Reads the specified item from the cache or generate it.
     *
     * @template T
     * @param  mixed  $key
     * @param  null|callable(CacheDependencies|null &$dependencies=):T  $generator
     * @param  CacheDependencies|null  $dependencies
     *
     * @return T
     */
    public function load(mixed $key, ?callable $generator = null, ?array $dependencies = null): mixed {
        $storageKey = $this->generateKey($key);
        $data = $this->getStorage()->read($storageKey);
        if ($data === null && $generator) {
            $this->logLoadedKey($key, true);
            self::$miss++;
            $this->getStorage()->lock($storageKey);
            try {
                $dependencies ??= [];
                $data = $generator($dependencies);
            } catch (Throwable $e) {
                $this->getStorage()->remove($storageKey);
                throw $e;
            }

            $this->save($key, $data, $dependencies);
        } else if ($data !== null) {
            $this->logLoadedKey($key);
            self::$hit++;
        } else {
            self::$miss++;
        }

        return $data;
    }

    /**
     * @param  mixed  $key
     * @param  bool  $miss
     *
     * @return void
     */
    private function logLoadedKey(mixed $key, bool $miss = false): void {
        if (!$this->debug) {
            return;
        }
        $key = is_scalar($key) ? (string) $key : serialize($key);
        if (!isset(self::$loadedKeys[$key])) {
            self::$loadedKeys[$key] = [0, 0];
        }
        self::$loadedKeys[$key][0]++;
        if ($miss) {
            self::$loadedKeys[$key][1]++;
        }
    }

    /**
     * @return int
     */
    public function getCalls(): int {
        return self::$hit + self::$miss;
    }
}
