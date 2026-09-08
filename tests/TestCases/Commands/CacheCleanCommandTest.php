<?php

declare(strict_types=1);

namespace TestCases\Commands;

use Lsr\Caching\Cache;
use Lsr\Caching\Commands\CacheCleanCommand;
use Nette\Caching\Cache as NetteCache;
use Nette\Caching\Storage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheCleanCommandTest extends TestCase
{
    public function test_cleans_the_entire_configured_cache(): void {
        $storage = $this->createMock(Storage::class);
        $storage->expects(self::once())
            ->method('clean')
            ->with([NetteCache::All => true]);

        $tester = new CommandTester(new CacheCleanCommand(new Cache($storage)));
        $code = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $code);
    }

    public function test_cleans_only_requested_tags(): void {
        $storage = $this->createMock(Storage::class);
        $storage->expects(self::once())
            ->method('clean')
            ->with([NetteCache::Tags => ['players', 'arenas']]);

        $tester = new CommandTester(new CacheCleanCommand(new Cache($storage)));
        $code = $tester->execute([
            '--tag' => ['players', 'arenas'],
        ]);

        self::assertSame(Command::SUCCESS, $code);
    }
}
