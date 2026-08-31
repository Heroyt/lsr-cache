<?php

declare(strict_types=1);

namespace TestCases\Commands;

use Lsr\Caching\DI\CacheExtension;
use Nette\DI\Compiler;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

final class CacheCommandRegistrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = dirname(__DIR__, 2) . '/tmp/commands-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        FileSystem::delete($this->directory);
    }

    public function testDeclaresCommandWhenConsoleSupportIsAvailable(): void {
        $compiler = new Compiler();
        $compiler->addExtension('cache', new CacheExtension());
        $compiler->addConfig([
            'cache' => [
                'cacheDir' => $this->directory,
            ],
        ]);

        $compiler->processExtensions();

        self::assertTrue($compiler->getContainerBuilder()->hasDefinition('cache.commands.clean'));
        self::assertTrue(
            $compiler->getContainerBuilder()
                ->getDefinition('cache.commands.clean')
                ->getTag('console.command')
        );
    }

    public function testCommandDeclarationCanBeDisabled(): void {
        $compiler = new Compiler();
        $compiler->addExtension('cache', new CacheExtension());
        $compiler->addConfig([
            'cache' => [
                'cacheDir' => $this->directory,
                'commands' => false,
            ],
        ]);

        $compiler->processExtensions();

        self::assertFalse($compiler->getContainerBuilder()->hasDefinition('cache.commands.clean'));
    }
}
