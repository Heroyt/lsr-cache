<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Lifecycle\CacheLifecycleScopeInterface;
use Throwable;

final readonly class RecordingCacheLifecycleScope implements CacheLifecycleScopeInterface
{
    public function __construct(private RecordingCacheLifecycleHook $hook) {
    }

    public function recordException(Throwable $exception): void {
        $this->hook->exceptions[] = $exception;
    }

    public function complete(int $hits, int $misses, int $generated): void {
        $this->hook->completions[] = compact('hits', 'misses', 'generated');
    }
}
