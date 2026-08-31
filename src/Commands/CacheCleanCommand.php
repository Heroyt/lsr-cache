<?php

declare(strict_types=1);

namespace Lsr\Caching\Commands;

use Lsr\Caching\Cache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:clean', description: 'Clean system cache.', aliases: ['cache:clear'])]
final class CacheCleanCommand extends Command
{
    public function __construct(
        private readonly Cache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addOption(
            'tag',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'If set, only the records with specified tags will be removed.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $tags = $input->getOption('tag');
        if (is_array($tags) && count($tags) > 0) {
            $this->cache->clean([\Nette\Caching\Cache::Tags => $tags]);
        } else {
            $this->cache->clean([\Nette\Caching\Cache::All => true]);
        }
        $output->writeln('<info>Successfully purged system cache</info>');
        return Command::SUCCESS;
    }
}
