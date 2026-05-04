<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Setup\SampleData;

use Disrex\SampleDataThemesCore\Model\FixtureRunner;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Magento\Framework\Setup\SampleData\InstallerInterface;

/**
 * Implements the Magento SampleData InstallerInterface so that themed
 * content can be invoked through the core sample-data lifecycle if a
 * downstream integration ever needs it.
 *
 * The first registered theme is used; in practice this entry point is
 * rarely hit because the CLI commands take precedence. It exists primarily
 * for parity with `magento/module-*-sample-data` packages.
 */
class Installer implements InstallerInterface
{
    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly FixtureRunner $runner
    ) {
    }

    public function install(): void
    {
        if ($this->registry->isEmpty()) {
            return;
        }
        $themes = $this->registry->all();
        $first = reset($themes);
        if ($first !== false) {
            $this->runner->run($first);
        }
    }
}
