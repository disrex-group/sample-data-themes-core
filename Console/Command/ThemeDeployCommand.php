<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Console\Command;

use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Exception\MissingStoreviewException;
use Disrex\SampleDataThemesCore\Helper\Fixture\PrimaryLocalePromoter;
use Disrex\SampleDataThemesCore\Helper\Fixture\StoreviewManager;
use Disrex\SampleDataThemesCore\Model\FixtureRunner;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ThemeDeployCommand extends Command
{
    public const NAME = 'sampledata:theme:deploy';

    public const OPT_THEME = 'theme';
    public const OPT_LOCALES = 'locales';
    public const OPT_DEFAULT_LOCALE = 'default-locale';
    public const OPT_AUTO_CREATE = 'auto-create-storeviews';
    public const OPT_SKIP_MEDIA = 'skip-media';
    public const OPT_DRY_RUN = 'dry-run';
    public const OPT_FORCE = 'force';
    public const OPT_SKIP_REINDEX = 'skip-reindex';
    public const OPT_PRIMARY_LOCALE = 'primary-locale';

    /**
     * Indexers refreshed automatically after a deploy run. Kept narrow so
     * we don't slow down the command with unrelated heavy reindexes — these
     * cover the visibility paths we know our fixtures touch (product EAV,
     * category-product, prices, stock, search).
     */
    private const POST_DEPLOY_INDEXERS = [
        'catalog_product_attribute',
        'catalog_product_category',
        'catalog_category_product',
        'catalog_product_price',
        'cataloginventory_stock',
        'catalogsearch_fulltext',
    ];

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly FixtureRunner $runner,
        private readonly StoreviewManager $storeviewManager,
        private readonly AppState $appState,
        private readonly Registry $magentoRegistry,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly PrimaryLocalePromoter $primaryLocalePromoter,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Deploy a sample-data theme.')
            ->addOption(self::OPT_THEME, null, InputOption::VALUE_REQUIRED, 'Theme code (skip the prompt).')
            ->addOption(
                self::OPT_LOCALES,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated locales to import. Defaults to all locales the theme supports.'
            )
            ->addOption(
                self::OPT_DEFAULT_LOCALE,
                null,
                InputOption::VALUE_REQUIRED,
                'Locale whose content goes to storeviews without an explicit match.'
            )
            ->addOption(
                self::OPT_AUTO_CREATE,
                null,
                InputOption::VALUE_NONE,
                'Auto-create missing storeviews instead of failing.'
            )
            ->addOption(self::OPT_SKIP_MEDIA, null, InputOption::VALUE_NONE, 'Skip image import.')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Print plan; do not write.')
            ->addOption(self::OPT_FORCE, null, InputOption::VALUE_NONE, 'Re-run even if already installed.')
            ->addOption(
                self::OPT_SKIP_REINDEX,
                null,
                InputOption::VALUE_NONE,
                'Skip the catalog reindex pass that runs after a successful deploy.'
            )
            ->addOption(
                self::OPT_PRIMARY_LOCALE,
                null,
                InputOption::VALUE_REQUIRED,
                'Locale to promote onto the default storeview after fixtures land. '
                . 'Copies that locale\'s storeview EAV (product names, category names, '
                . 'attribute and option labels) onto store_id=1, switches its locale + '
                . 'currency, and regenerates URL rewrites. Useful for single-store demo '
                . 'installs that should render in the chosen language without cookie '
                . 'switching or path prefixes.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->prepareEnvironment();

        $theme = $this->resolveTheme($input, $output);
        if (!$theme) {
            return Command::FAILURE;
        }

        $locales = $this->resolveLocales($input, $theme);

        $output->writeln(sprintf('<info>Theme:</info> %s — %s', $theme->getCode(), $theme->getName()));
        $output->writeln(sprintf('<info>Locales:</info> %s', implode(', ', $locales)));

        $autoCreate = (bool) $input->getOption(self::OPT_AUTO_CREATE);
        if (!$this->ensureStoreviews($locales, $autoCreate, $output)) {
            return Command::FAILURE;
        }

        if ($input->getOption(self::OPT_DRY_RUN)) {
            $output->writeln('<comment>Dry run — fixtures listed but not executed:</comment>');
            foreach ($theme->getFixtures() as $cls) {
                $output->writeln('  - ' . $cls);
            }
            return Command::SUCCESS;
        }

        $this->signalDeployContext($input, $theme, $locales);

        $output->writeln(sprintf('<info>Deploying theme:</info> %s', $theme->getCode()));
        $result = $this->runner->run($theme, $output);

        $this->summarize($result, $output);

        if ($result->isSuccessful()) {
            $this->maybePromotePrimaryLocale($input, $theme, $output);
        }

        if ($result->isSuccessful() && !$input->getOption(self::OPT_SKIP_REINDEX)) {
            $this->reindexCatalog($output);
        }

        $output->writeln('');
        $output->writeln(
            '<comment>Tip:</comment> run <info>bin/magento setup:upgrade</info> if this is the first deploy.'
        );

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Run the optional --primary-locale step.
     */
    private function maybePromotePrimaryLocale(
        InputInterface $input,
        ThemeInterface $theme,
        OutputInterface $output
    ): void {
        $primaryLocale = $input->getOption(self::OPT_PRIMARY_LOCALE);
        if (!is_string($primaryLocale) || $primaryLocale === '') {
            return;
        }
        if (!in_array($primaryLocale, $theme->getSupportedLocales(), true)) {
            $output->writeln(sprintf(
                '<error>Primary locale "%s" is not in the theme\'s supported set (%s); skipping promotion.</error>',
                $primaryLocale,
                implode(', ', $theme->getSupportedLocales())
            ));
            return;
        }
        $skuPrefix = $theme->getSkuPrefix();
        if ($skuPrefix === '') {
            $output->writeln(
                '<error>Theme declares no SKU prefix; refusing to overlay onto the default storeview '
                . '(would risk touching unrelated catalog data).</error>'
            );
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Promoting locale "%s" onto the default storeview…</info>',
            $primaryLocale
        ));
        $result = $this->primaryLocalePromoter->promote($primaryLocale, $skuPrefix);
        switch ($result->status) {
            case 'ok':
                $output->writeln(sprintf(
                    '  <info>✓</info> overlay applied: store_id %d ← store_id %d (%d product rows, '
                    . '%d category rows, %d option labels, %d attribute labels), '
                    . '%d category rewrites, %d product rewrites',
                    $result->stats['target_store_id'] ?? 1,
                    $result->stats['source_store_id'] ?? 0,
                    $result->stats['product_rows'] ?? 0,
                    $result->stats['category_rows'] ?? 0,
                    $result->stats['option_rows'] ?? 0,
                    $result->stats['label_rows'] ?? 0,
                    $result->stats['category_rewrites'] ?? 0,
                    $result->stats['product_rewrites'] ?? 0
                ));
                break;
            case 'skipped':
                $output->writeln(sprintf('  <comment>↷</comment> %s', $result->message));
                break;
            case 'failed':
            default:
                $output->writeln(sprintf('  <error>✗ %s</error>', $result->message));
                break;
        }
    }

    /**
     * Refresh the indexers that surface fixture data (image attributes,
     * category links, prices, stock, search) so the admin grid and
     * storefront see the new products immediately.
     *
     * On installs whose indexers are in "Update by Schedule" mode, a
     * straight `reindexAll()` call sometimes short-circuits if the indexer
     * already considers itself valid (e.g. it ran during a fixture save
     * before the locale promotion overlaid new EAV rows). Invalidating
     * each indexer first guarantees the rebuild actually runs and emits
     * row counts, which is what the operator expects to see after a
     * deploy.
     *
     * Failures here don't abort the deploy — fixtures already landed; the
     * operator can re-run `bin/magento indexer:reindex` if needed.
     */
    private function reindexCatalog(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>Reindex:</info>');
        foreach (self::POST_DEPLOY_INDEXERS as $code) {
            try {
                $indexer = $this->indexerRegistry->get($code);
                $indexer->invalidate();
                $indexer->reindexAll();
                $output->writeln(sprintf('  <info>✓</info> %s', $code));
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '  <error>✗ %s — %s</error>',
                    $code,
                    $e->getMessage()
                ));
            }
        }

        // After the indexers rebuild, the storefront page cache and block
        // cache still hold the *pre-deploy* HTML — empty category pages,
        // stale menus, missing facets. Without this flush, an operator
        // following the deploy with a fresh browser request sees "no
        // products found" until the 24h cache TTL expires (or they run
        // `bin/magento cache:clean` manually).
        //
        // We flush the bare minimum needed for the storefront to reflect
        // the new catalog: full_page (Varnish/built-in FPC), block_html
        // (rendered block fragments — category lists, navigation), and
        // collections (collection-results cache used by category lists).
        $output->writeln('');
        $output->writeln('<info>Cache flush:</info>');
        foreach (['full_page', 'block_html', 'collections', 'config'] as $cacheType) {
            try {
                $this->cacheTypeList->cleanType($cacheType);
                $output->writeln(sprintf('  <info>✓</info> %s', $cacheType));
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '  <error>✗ %s — %s</error>',
                    $cacheType,
                    $e->getMessage()
                ));
            }
        }
    }

    private function resolveTheme(InputInterface $input, OutputInterface $output): ?ThemeInterface
    {
        if ($this->registry->isEmpty()) {
            $output->writeln('<error>No sample-data themes are registered.</error>');
            return null;
        }

        $code = $input->getOption(self::OPT_THEME);
        if (is_string($code) && $code !== '') {
            if (!$this->registry->has($code)) {
                $output->writeln(sprintf('<error>Theme "%s" is not registered.</error>', $code));
                return null;
            }
            return $this->registry->get($code);
        }

        $codes = array_keys($this->registry->all());
        if (count($codes) === 1) {
            return $this->registry->get($codes[0]);
        }

        $choices = [];
        foreach ($this->registry->all() as $t) {
            $choices[$t->getCode()] = sprintf(
                '%s — %s (locales: %s)',
                $t->getName(),
                $t->getDescription(),
                implode(', ', $t->getSupportedLocales())
            );
        }
        $question = new ChoiceQuestion('<question>Choose a theme:</question>', $choices);
        $question->setErrorMessage('Theme "%s" is not registered.');

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $selected = (string) $helper->ask($input, $output, $question);
        return $this->registry->get($selected);
    }

    /**
     * @return array<int, string>
     */
    private function resolveLocales(InputInterface $input, ThemeInterface $theme): array
    {
        $raw = $input->getOption(self::OPT_LOCALES);
        if (is_string($raw) && $raw !== '') {
            $requested = array_filter(array_map('trim', explode(',', $raw)));
            $supported = $theme->getSupportedLocales();
            $unknown = array_diff($requested, $supported);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Theme "%s" does not ship locales: %s. Supported: %s.',
                    $theme->getCode(),
                    implode(', ', $unknown),
                    implode(', ', $supported)
                ));
            }
            return array_values($requested);
        }
        return $theme->getSupportedLocales();
    }

    /**
     * @param array<int, string> $locales
     */
    private function ensureStoreviews(array $locales, bool $autoCreate, OutputInterface $output): bool
    {
        $output->writeln('<info>Storeview check:</info>');
        $allOk = true;
        foreach ($locales as $locale) {
            try {
                $ids = $this->storeviewManager->ensureStoreviewForLocale($locale, $autoCreate);
                $output->writeln(sprintf(
                    '  <info>✓</info> %s → store ids %s',
                    $locale,
                    implode(',', $ids)
                ));
            } catch (MissingStoreviewException $e) {
                $output->writeln(sprintf('  <error>✗ %s — %s</error>', $locale, $e->getMessage()));
                $allOk = false;
            }
        }
        return $allOk;
    }

    /**
     * @param array<int, string> $locales
     */
    private function signalDeployContext(InputInterface $input, ThemeInterface $theme, array $locales): void
    {
        if ($this->magentoRegistry->registry('disrex_sample_data_theme_context') === null) {
            $this->magentoRegistry->register('disrex_sample_data_theme_context', [
                'theme' => $theme->getCode(),
                'locales' => $locales,
                'skip_media' => (bool) $input->getOption(self::OPT_SKIP_MEDIA),
                'force' => (bool) $input->getOption(self::OPT_FORCE),
            ]);
        }
    }

    private function prepareEnvironment(): void
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // Area already set.
        }
    }

    private function summarize(\Disrex\SampleDataThemesCore\Model\RunResult $result, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d ok, %d failed.',
            count($result->getSuccesses()),
            count($result->getFailures())
        ));
        if (!$result->isSuccessful()) {
            $output->writeln('<error>Failures:</error>');
            foreach ($result->getFailures() as $failure) {
                $output->writeln(sprintf('  - %s: %s', $failure['class'], $failure['message']));
            }
        }
    }
}
