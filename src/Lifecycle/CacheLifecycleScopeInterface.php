<?php

declare(strict_types=1);

namespace Lsr\Caching\Lifecycle;

use Throwable;

interface CacheLifecycleScopeInterface
{
    public function recordException(Throwable $exception): void;

    public function complete(int $hits, int $misses, int $generated): void;
}
