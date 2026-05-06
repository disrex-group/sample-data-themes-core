<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

use Disrex\SampleDataThemesCore\Api\ConfigurableFixtureInterface;
use Disrex\SampleDataThemesCore\Api\FixtureInterface;
use Disrex\SampleDataThemesCore\Api\StateAwareFixtureInterface;
use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Helper\Fixture\ProductImporter;
use Disrex\SampleDataThemesCore\Model\ConflictAction;
use Disrex\SampleDataThemesCore\Model\DeployPlan;
use Disrex\SampleDataThemesCore\Model\Fixture\AbstractCsvFixture;
use Disrex\SampleDataThemesCore\Model\FixtureAliasResolver;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Cache\TypeListInterface as CacheTypeList;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Executes a theme's fixtures in declared order (and reverse order on
 * rollback). Failures in one fixture do not abort the run — the runner logs
 * the error and continues, since a partial install is usually more useful
 * than nothing.
 */
class FixtureRunner
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger,
        private readonly EavConfig $eavConfig,
        private readonly CacheTypeList $cacheTypeList,
        private readonly ProductImporter $productImporter
    ) {
    }

    public function run(
        ThemeInterface $theme,
        ?OutputInterface $output = null,
        ?DeployPlan $plan = null
    ): RunResult {
        $plan ??= new DeployPlan();
        $result = new RunResult($theme->getCode());

        // Magento does not always cascade `url_rewrite` rows when products
        // are deleted, so re-running an import (or partial cleanup between
        // runs) leaves orphan rewrites that block fresh inserts. Purge them
        // up-front so product fixtures have a clean URL key namespace.
        $cleaned = $this->productImporter->cleanOrphanProductUrlRewrites();
        if ($cleaned > 0) {
            $output?->writeln(sprintf(
                '  <comment>Cleaned %d orphan product URL rewrite(s) before running fixtures.</comment>',
                $cleaned
            ));
        }

        foreach ($theme->getFixtures() as $fixtureClass) {
            $shortName = $this->shortName($fixtureClass);

            if (!$plan->shouldRun($shortName)) {
                $output?->writeln(sprintf('  <comment>↷ %s — skipped by plan</comment>', $shortName));
                $result->addSkipped($fixtureClass, 'skipped by plan');
                continue;
            }

            $output?->writeln(sprintf('  <comment>→</comment> %s', $fixtureClass));
            try {
                $fixture = $this->createFixture($fixtureClass);

                // Apply runtime options (reviews-per-product, star skew, …)
                if ($fixture instanceof ConfigurableFixtureInterface) {
                    $opts = $plan->optionsFor($shortName);
                    if ($opts !== []) {
                        $fixture->configure($opts);
                    }
                }

                // Honour the per-fixture conflict action when state is
                // already present and the fixture can describe its state.
                if ($fixture instanceof StateAwareFixtureInterface) {
                    $existing = $fixture->count();
                    if ($existing > 0) {
                        $action = $plan->conflictAction($shortName);
                        if ($action === ConflictAction::Skip) {
                            $output?->writeln(sprintf(
                                '    <comment>↷ %d existing — skip per plan</comment>',
                                $existing
                            ));
                            $result->addSkipped(
                                $fixtureClass,
                                sprintf('skip: %d existing', $existing)
                            );
                            continue;
                        }
                        if ($action === ConflictAction::Reset) {
                            $cleared = $fixture->clear();
                            $output?->writeln(sprintf(
                                '    <comment>⟲ cleared %d existing entries before re-run</comment>',
                                $cleared
                            ));
                        }
                    }
                }

                if ($plan->dryRun) {
                    $output?->writeln('    <comment>↷ dry-run — execute() skipped</comment>');
                    $result->addSkipped($fixtureClass, 'dry-run');
                    continue;
                }

                $fixture->execute();
                // EAV / config caches go stale as soon as a fixture creates
                // attributes, attribute sets or categories. Invalidate
                // between fixtures so the next one sees fresh data.
                $this->refreshEavCache();
                $label = $this->labelWithRowStats($fixture);
                $result->addSuccess($fixtureClass, $label);
                $output?->writeln(sprintf('    <info>✓ %s</info>', $label));
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '[disrex/sample-data-themes] Fixture %s failed: %s',
                        $fixtureClass,
                        $e->getMessage()
                    ),
                    ['exception' => $e, 'theme' => $theme->getCode()]
                );
                $result->addFailure($fixtureClass, $e->getMessage());
                $output?->writeln(sprintf('    <error>✗ %s</error>', $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * The plan's identifiers are stable short class names, not FQCNs:
     *   Disrex\…\Setup\Fixtures\SimpleProductFixture → SimpleProductFixture
     *
     * Using FQCNs in user-facing flags would tie the public API to an
     * internal namespace.
     */
    public function shortName(string $fixtureClass): string
    {
        return FixtureAliasResolver::shortNameOf($fixtureClass);
    }

    public function rollback(ThemeInterface $theme, ?OutputInterface $output = null): RunResult
    {
        $result = new RunResult($theme->getCode());

        foreach (array_reverse($theme->getFixtures()) as $fixtureClass) {
            $output?->writeln(sprintf('  <comment>↶</comment> %s', $fixtureClass));
            try {
                $fixture = $this->createFixture($fixtureClass);
                $fixture->rollback();
                $result->addSuccess($fixtureClass, 'rollback: ' . $fixture->getLabel());
                $output?->writeln(sprintf('    <info>✓ rolled back: %s</info>', $fixture->getLabel()));
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '[disrex/sample-data-themes] Rollback of %s failed: %s',
                        $fixtureClass,
                        $e->getMessage()
                    ),
                    ['exception' => $e, 'theme' => $theme->getCode()]
                );
                $result->addFailure($fixtureClass, $e->getMessage());
                $output?->writeln(sprintf('    <error>✗ %s</error>', $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * Append per-row counters to a fixture's label when available, so the
     * CLI shows "X rows, Y skipped" rather than the static label that
     * lies about partial failures.
     */
    private function labelWithRowStats(FixtureInterface $fixture): string
    {
        $label = $fixture->getLabel();
        if ($fixture instanceof AbstractCsvFixture) {
            $ok = $fixture->getSucceededRowCount();
            $bad = $fixture->getFailedRowCount();
            if ($ok + $bad > 0) {
                $label .= sprintf(
                    ' (%d row%s%s)',
                    $ok,
                    $ok === 1 ? '' : 's',
                    $bad > 0 ? sprintf(', %d skipped', $bad) : ''
                );
            }
        }
        return $label;
    }

    /**
     * Clear in-memory EAV state and invalidate the persistent EAV / config
     * caches so subsequent fixtures see attributes and options created by
     * this fixture. Called between every fixture in {@see run()}.
     */
    private function refreshEavCache(): void
    {
        $this->eavConfig->clear();
        $this->cacheTypeList->cleanType('eav');
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Indirected so tests can override fixture instantiation without
     * requiring a real ObjectManager.
     */
    protected function createFixture(string $fixtureClass): FixtureInterface
    {
        /** @var FixtureInterface $instance */
        $instance = $this->objectManager->create($fixtureClass);

        if (!$instance instanceof FixtureInterface) {
            throw new \LogicException(sprintf(
                'Fixture class "%s" must implement %s.',
                $fixtureClass,
                FixtureInterface::class
            ));
        }
        return $instance;
    }
}
