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
    public function testCleansTheEntireConfiguredCache(): void {
        $storage = $this->createMock(Storage::class);
        $storage->expects(self::once())
            ->method('clean')
            ->with([NetteCache::All => true]);

        $tester = new CommandTester(new CacheCleanCommand(new Cache($storage)));
        $code = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Successfully purged system cache', $tester->getDisplay());
    }

    public function testCleansOnlyRequestedTags(): void {
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
