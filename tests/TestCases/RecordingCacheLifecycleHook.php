<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Lifecycle\CacheLifecycleHookInterface;
use Lsr\Caching\Lifecycle\CacheLifecycleScopeInterface;
use RuntimeException;
use Throwable;

final class RecordingCacheLifecycleHook implements CacheLifecycleHookInterface
{
    /** @var list<array{operation: string, itemCount: int}> */
    public array $begins = [];
    /** @var list<array{hits: int, misses: int, generated: int}> */
    public array $completions = [];
    /** @var list<Throwable> */
    public array $exceptions = [];
    public bool $failBegin = false;

    public function begin(string $operation, int $itemCount): CacheLifecycleScopeInterface {
        if ($this->failBegin) {
            throw new RuntimeException('Hook failure');
        }
        $this->begins[] = ['operation' => $operation, 'itemCount' => $itemCount];
        return new RecordingCacheLifecycleScope($this);
    }
}
