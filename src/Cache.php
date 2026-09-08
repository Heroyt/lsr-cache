<?php

declare(strict_types=1);

namespace Lsr\Caching;

use Lsr\Caching\Lifecycle\CacheLifecycleHookInterface;
use Lsr\Caching\Lifecycle\CacheLifecycleScopeInterface;
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
 *     callbacks?:list<array{0:callable, 1?:mixed, 2?:mixed}>,
 *     namespaces?:non-empty-string[],
 *     }
 */
class Cache extends \Nette\Caching\Cache
{
    public static int $hit = 0;
    public static int $miss = 0;
    /** @var array<string, array{0:int, 1:int}> */
    public static array $loadedKeys = [];

    private ?CacheLifecycleHookInterface $lifecycleHook = null;

    public function __construct(
        Storage        $storage,
        ?string        $namespace = null,
        protected bool $debug = true,
    ) {
        parent::__construct($storage, $namespace);
    }

    public function setLifecycleHook(CacheLifecycleHookInterface $hook): static {
        $this->lifecycleHook = $hook;
        return $this;
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
        if ( ! array_all($keys, static fn ($key) => is_scalar($key))) {
            throw new InvalidArgumentException('Only scalar keys are allowed in bulkLoad()');
        }

        $result = [];
        if ( ! $this->getStorage() instanceof BulkReader) {
            foreach ($keys as $key) {
                $result[$key] = $this->load(
                    $key,
                    $generator !== null
                        ? static fn (?array &$dependencies = null) => $generator($key, $dependencies)
                        : null,
                );
            }

            return $result;
        }

        $scope = $this->beginLifecycle('bulk_load', count($keys));
        $hits = 0;
        $misses = 0;
        $generated = 0;

        try {
            $storageKeys = array_map([$this, 'generateKey'], $keys);
            $cacheData = $this->getStorage()->bulkRead($storageKeys);
            foreach ($keys as $i => $key) {
                $storageKey = $storageKeys[$i];
                if (isset($cacheData[$storageKey])) {
                    $this->logLoadedKey($key);
                    self::$hit++;
                    $hits++;
                    $result[$key] = $cacheData[$storageKey];
                } elseif ($generator) {
                    $this->logLoadedKey($key, true);
                    self::$miss++;
                    $misses++;
                    $ignoredHits = 0;
                    $ignoredMisses = 0;
                    $ignoredGenerated = 0;
                    $result[$key] = $this->loadValue(
                        $key,
                        fn (?array &$dependencies = null) => $generator($key, $dependencies),
                        null,
                        $ignoredHits,
                        $ignoredMisses,
                        $ignoredGenerated,
                    );
                    $generated += $ignoredGenerated;
                } else {
                    $this->logLoadedKey($key, true);
                    self::$miss++;
                    $misses++;
                    $result[$key] = null;
                }
            }

            return $result;
        } catch (Throwable $exception) {
            $this->recordLifecycleException($scope, $exception);
            throw $exception;
        } finally {
            $this->completeLifecycle($scope, $hits, $misses, $generated);
        }
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
        $scope = $this->beginLifecycle('load', 1);
        $hits = 0;
        $misses = 0;
        $generated = 0;

        try {
            return $this->loadValue($key, $generator, $dependencies, $hits, $misses, $generated);
        } catch (Throwable $exception) {
            $this->recordLifecycleException($scope, $exception);
            throw $exception;
        } finally {
            $this->completeLifecycle($scope, $hits, $misses, $generated);
        }
    }

    /**
     * @param null|callable(CacheDependencies|null &$dependencies=):mixed $generator
     * @param CacheDependencies|null $dependencies
     */
    private function loadValue(
        mixed $key,
        ?callable $generator,
        ?array $dependencies,
        int &$hits,
        int &$misses,
        int &$generated,
    ): mixed {
        $storageKey = $this->generateKey($key);
        $data = $this->getStorage()->read($storageKey);
        if ($data === null && $generator) {
            $this->logLoadedKey($key, true);
            self::$miss++;
            $misses++;
            $generated++;
            $this->getStorage()->lock($storageKey);
            try {
                $dependencies ??= [];
                $data = $generator($dependencies);
            } catch (Throwable $exception) {
                $this->getStorage()->remove($storageKey);
                throw $exception;
            }

            $this->save($key, $data, $dependencies);
        } elseif ($data !== null) {
            $this->logLoadedKey($key);
            self::$hit++;
            $hits++;
        } else {
            self::$miss++;
            $misses++;
        }

        return $data;
    }

    private function beginLifecycle(string $operation, int $itemCount): ?CacheLifecycleScopeInterface {
        try {
            return $this->lifecycleHook?->begin($operation, $itemCount);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordLifecycleException(
        ?CacheLifecycleScopeInterface $scope,
        Throwable $exception,
    ): void {
        try {
            $scope?->recordException($exception);
        } catch (Throwable) {
            // Lifecycle hooks must never affect cache operations.
        }
    }

    private function completeLifecycle(
        ?CacheLifecycleScopeInterface $scope,
        int $hits,
        int $misses,
        int $generated,
    ): void {
        try {
            $scope?->complete($hits, $misses, $generated);
        } catch (Throwable) {
            // Lifecycle hooks must never affect cache operations.
        }
    }

    /**
     * @param  mixed  $key
     * @param  bool  $miss
     *
     * @return void
     */
    private function logLoadedKey(mixed $key, bool $miss = false): void {
        if ( ! $this->debug) {
            return;
        }
        $key = is_scalar($key) ? (string) $key : serialize($key);
        if ( ! isset(self::$loadedKeys[$key])) {
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
