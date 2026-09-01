<?php

declare(strict_types=1);

namespace Lsr\Caching\Lifecycle;

interface CacheLifecycleHookInterface
{
    public function begin(string $operation, int $itemCount): CacheLifecycleScopeInterface;
}
