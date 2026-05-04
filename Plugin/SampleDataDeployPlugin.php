<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Plugin;

use Disrex\SampleDataThemesCore\Console\Command\ThemeDeployCommand;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * When at least one theme is registered, intercept the stock
 * `bin/magento sampledata:deploy` and offer the themed alternative. Falls
 * straight through to the original behaviour otherwise.
 *
 * Subject is `Magento\SampleData\Console\Command\SampleDataDeployCommand`,
 * which is a Symfony Console command. It is not statically referenced here
 * to avoid a hard dependency on `magento/module-sample-data` — when that
 * module is absent, this plugin is never instantiated.
 */
class SampleDataDeployPlugin
{
    public function __construct(private readonly ThemeRegistry $registry)
    {
    }

    /**
     * @param Command $subject The wrapped sampledata:deploy command.
     * @param callable(InputInterface, OutputInterface): int $proceed
     */
    public function aroundExecute(
        Command $subject,
        callable $proceed,
        InputInterface $input,
        OutputInterface $output
    ): int {
        if ($this->registry->isEmpty()) {
            return $proceed($input, $output);
        }

        $output->writeln(
            '<info>Themed sample data is available.</info> '
            . 'You can install one of the registered themes instead of the stock Luma data.'
        );

        /** @var QuestionHelper $helper */
        $helper = $subject->getHelper('question');
        $useTheme = $helper->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                '<question>Use a theme instead of stock sample data? [Y/n]</question> ',
                true
            )
        );

        if (!$useTheme) {
            return $proceed($input, $output);
        }

        $app = $subject->getApplication();
        if ($app === null) {
            // Should never happen — plugin is invoked from a running console
            // app — but be defensive rather than crash.
            return $proceed($input, $output);
        }
        $themed = $app->find(ThemeDeployCommand::NAME);
        return $themed->run($input, $output);
    }
}
