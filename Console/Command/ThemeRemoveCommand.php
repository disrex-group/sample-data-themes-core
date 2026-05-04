<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Console\Command;

use Disrex\SampleDataThemesCore\Model\FixtureRunner;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ThemeRemoveCommand extends Command
{
    public const NAME = 'sampledata:theme:remove';

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly FixtureRunner $runner,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Remove a previously deployed sample-data theme.')
            ->addOption(ThemeDeployCommand::OPT_THEME, null, InputOption::VALUE_REQUIRED, 'Theme code.')
            ->addOption('no-confirm', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // Already set.
        }

        $code = (string) $input->getOption(ThemeDeployCommand::OPT_THEME);
        if ($code === '' || !$this->registry->has($code)) {
            $output->writeln(sprintf(
                '<error>Pass --theme=CODE. Available: %s.</error>',
                implode(', ', array_keys($this->registry->all()))
            ));
            return Command::FAILURE;
        }
        $theme = $this->registry->get($code);

        if (!$input->getOption('no-confirm')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $confirm = new ConfirmationQuestion(sprintf(
                '<question>Remove all entities created by theme "%s"? [y/N]</question> ',
                $code
            ), false);
            if (!$helper->ask($input, $output, $confirm)) {
                $output->writeln('<comment>Cancelled.</comment>');
                return Command::SUCCESS;
            }
        }

        $output->writeln(sprintf('<info>Rolling back theme:</info> %s', $code));
        $result = $this->runner->rollback($theme, $output);

        $output->writeln(sprintf(
            '<info>Done.</info> %d ok, %d failed.',
            count($result->getSuccesses()),
            count($result->getFailures())
        ));
        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
