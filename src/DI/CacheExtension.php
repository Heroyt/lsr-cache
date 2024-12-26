<?php
declare(strict_types=1);

namespace Lsr\Caching\DI;

use Lsr\Caching\Cache;
use Lsr\Caching\Tracy\CacheTracyPanel;
use Nette;
use Nette\Caching\Storage;
use Nette\Caching\Storages\FileStorage;
use Nette\Caching\Storages\Journal;
use Nette\Caching\Storages\SQLiteJournal;
use Nette\DI\CompilerExtension;
use Nette\InvalidArgumentException;
use Nette\InvalidStateException;
use Nette\Utils\FileSystem;

/**
 * @property-read object{cacheDir:string,cacheFile:string,journalFile:string,debug:bool,namespace:string|null} $config
 */
class CacheExtension extends CompilerExtension
{

    public function getConfigSchema() : Nette\Schema\Schema {
        return Nette\Schema\Expect::structure(
            [
                'cacheDir'    => Nette\Schema\Expect::string()->required(),
                'cacheFile'   => Nette\Schema\Expect::string()->default('cache.db'),
                'journalFile' => Nette\Schema\Expect::string()->default('journal.db'),
                'debug'       => Nette\Schema\Expect::bool()->default(false),
                'namespace'       => Nette\Schema\Expect::string()->default(null),
            ]
        );
    }

    public function loadConfiguration() : void {
        $cacheDir = $this->config->cacheDir;
        if (!FileSystem::isAbsolute($cacheDir)) {
            throw new InvalidArgumentException("Cache directory must be absolute, '$cacheDir' given.");
        }
        FileSystem::createDir($cacheDir);
        if (!is_writable($cacheDir)) {
            throw new InvalidStateException("Make directory '$cacheDir' writable.");
        }

        $builder = $this->getContainerBuilder();

        if (extension_loaded('pdo_sqlite')) {
            $builder->addDefinition($this->prefix('journal'))
                    ->setType(Journal::class)
                    ->setFactory(
                        SQLiteJournal::class,
                        [
                            trailingSlashIt($cacheDir).$this->config->journalFile,
                        ]
                    );
        }

        $builder->addDefinition($this->prefix('storage'))
                ->setType(Storage::class)
                ->setFactory(FileStorage::class, [$cacheDir]);

        if ($this->name === 'cache') {
            if (extension_loaded('pdo_sqlite')) {
                $builder->addAlias('nette.cacheJournal', $this->prefix('journal'));
            }

            $builder->addAlias('cacheStorage', $this->prefix('storage'));
        }

        $builder->addDefinition($this->name)
                ->setType(Cache::class)
                ->setFactory(
                    Cache::class,
                    [
                        '@'.$this->prefix('storage'),
                        $this->config->namespace,
                        $this->config->debug,
                    ]
                );

        $builder->addDefinition($this->prefix('tracyPanel'))
            ->setType(CacheTracyPanel::class)
            ->setFactory(
                CacheTracyPanel::class,
                [
                    '@'.$this->name,
                ]
            );
    }
}