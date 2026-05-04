<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Console\Command;

use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ThemeListCommand extends Command
{
    public const NAME = 'sampledata:theme:list';

    public function __construct(
        private readonly ThemeRegistry $registry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('List sample-data themes registered in this Magento install.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->registry->isEmpty()) {
            $output->writeln('<comment>No sample-data themes are registered.</comment>');
            $output->writeln(
                'Install a theme package, e.g. <info>composer require disrex/sample-data-theme-home-living</info>.'
            );
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Code', 'Name', 'Version', 'Locales', 'Fixtures', 'Description']);

        foreach ($this->registry->all() as $theme) {
            $table->addRow([
                $theme->getCode(),
                $theme->getName(),
                $theme->getVersion(),
                implode(', ', $theme->getSupportedLocales()),
                (string) count($theme->getFixtures()),
                $theme->getDescription(),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
