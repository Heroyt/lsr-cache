<?php

declare(strict_types=1);

namespace TestCases;

use Lsr\Caching\Cache;
use Nette\Caching\Storages\FileStorage;
use Nette\Caching\Storages\SQLiteJournal;
use PHPUnit\Framework\TestCase;

class CacheFilesTest extends TestCase
{
    use CacheTestingTrait;

    protected function setUp(): void {
        // Recreate the journal DB
        $journalDb = TMP_DIR . 'journal.db';
        if (file_exists($journalDb)) {
            unlink($journalDb);
        }
        touch($journalDb);

        $cacheDir = TMP_DIR . 'cache';
        if (file_exists($cacheDir) && is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/*') as $file) {
                unlink($file);
            }
        }
        if (!file_exists($cacheDir) && !mkdir($cacheDir) && !is_dir($cacheDir)) {
            throw new \RuntimeException('Could not create cache directory');
        }

        $this->cache = new Cache(
            new FileStorage(
                $cacheDir,
                new SqliteJournal($journalDb)
            ),
        );
    }
}
