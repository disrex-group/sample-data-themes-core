<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Console\Command;

use Disrex\SampleDataThemesCore\Api\ConfigurableFixtureInterface;
use Disrex\SampleDataThemesCore\Api\StateAwareFixtureInterface;
use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Exception\MissingStoreviewException;
use Disrex\SampleDataThemesCore\Helper\Fixture\PrimaryLocalePromoter;
use Disrex\SampleDataThemesCore\Helper\Fixture\StoreviewManager;
use Disrex\SampleDataThemesCore\Model\ConflictAction;
use Disrex\SampleDataThemesCore\Model\DeployPlan;
use Disrex\SampleDataThemesCore\Model\FixtureAliasResolver;
use Disrex\SampleDataThemesCore\Model\FixtureRunner;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use Magento\Framework\ObjectManagerInterface;
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
use Symfony\Component\Console\Question\ConfirmationQuestion;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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
    public const OPT_SKIP = 'skip';
    public const OPT_ONLY = 'only';
    public const OPT_RESET = 'reset';
    public const OPT_OPT = 'opt';
    public const OPT_PROFILE = 'profile';
    public const OPT_INTERACTIVE = 'interactive';
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
        private readonly TypeListInterface $cacheTypeList,
        private readonly ObjectManagerInterface $objectManager,
        private readonly FixtureAliasResolver $aliasResolver
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
            )
            ->addOption(
                self::OPT_SKIP,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated fixture short-names to skip (e.g. ProductReviewsFixture,BundleProductFixture).'
            )
            ->addOption(
                self::OPT_ONLY,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated fixture short-names to run exclusively. All others are skipped.'
            )
            ->addOption(
                self::OPT_RESET,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated fixture short-names whose existing data should be cleared before re-import. '
                . 'Pass "all" to reset every state-aware fixture.'
            )
            ->addOption(
                self::OPT_OPT,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Per-fixture option, in the form FixtureShortName.key=value '
                . '(e.g. --opt=ProductReviewsFixture.per-product=2-5). Repeat for multiple.'
            )
            ->addOption(
                self::OPT_PROFILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Named preset of fixture selection + options. Looks for '
                . '_files/profiles/<name>.yaml inside the theme package. '
                . 'Flags override profile values.'
            )
            ->addOption(
                self::OPT_INTERACTIVE,
                'i',
                InputOption::VALUE_NONE,
                'Force the wizard prompt even when flags are given. The wizard runs '
                . 'automatically when no flags are passed and the terminal is interactive.'
            );

        // Flat per-fixture option flags (--reviews-per-product=2-5).
        // Registered dynamically from each registered theme's
        // ConfigurableFixtureInterface implementations. Building this at
        // configure() time so they appear in --help.
        $this->registerFlatOptionFlags();
    }

    /**
     * For every registered theme's fixtures that implement
     * {@see ConfigurableFixtureInterface}, expose each option as a
     * flat top-level CLI flag named --<alias>-<key>.
     *
     * Symfony Console caches the option list at command construction,
     * so the flags must be added BEFORE parse() runs — i.e. inside
     * configure(). The trade-off: a fixture's options must be known
     * when the command boots; describeOptions() therefore can't depend
     * on runtime state.
     */
    private function registerFlatOptionFlags(): void
    {
        if ($this->registry->isEmpty()) {
            return;
        }
        foreach ($this->registry->all() as $theme) {
            $this->aliasResolver->build($theme);
            foreach ($theme->getFixtures() as $fqcn) {
                if (!is_subclass_of($fqcn, ConfigurableFixtureInterface::class)) {
                    continue;
                }
                $shortName = $this->runner->shortName($fqcn);
                $alias = $this->aliasResolver->aliasFor($shortName);
                $schema = $this->describeOptionsFor($fqcn);
                foreach ($schema as $key => $meta) {
                    $flag = sprintf('%s-%s', $alias, $key);
                    if ($this->getDefinition()->hasOption($flag)) {
                        // Two themes registered the same alias+key —
                        // first one wins; later ones reuse the same flag.
                        continue;
                    }
                    $description = is_string($meta['description'] ?? null)
                        ? (string) $meta['description']
                        : sprintf('Configures %s.%s', $alias, $key);
                    $default = $meta['default'] ?? null;
                    $this->addOption(
                        $flag,
                        null,
                        InputOption::VALUE_REQUIRED,
                        $description . (is_scalar($default) ? sprintf(' (default: %s)', (string) $default) : '')
                    );
                }
            }
        }
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

        $plan = $this->buildDeployPlan($input, $output, $theme);

        if ($plan->dryRun || $input->getOption(self::OPT_DRY_RUN)) {
            $output->writeln('<comment>Dry run — fixture plan:</comment>');
            $this->renderPlanPreview($plan, $theme, $output);
            return Command::SUCCESS;
        }

        $this->signalDeployContext($input, $theme, $locales);

        $output->writeln(sprintf('<info>Deploying theme:</info> %s', $theme->getCode()));
        $result = $this->runner->run($theme, $output, $plan);

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
        if ($result->getSkipped() !== []) {
            $output->writeln('<comment>Skipped:</comment>');
            foreach ($result->getSkipped() as $s) {
                $output->writeln(sprintf('  - %s (%s)', $s['class'], $s['reason']));
            }
        }
    }

    /**
     * Build a DeployPlan from (in order of precedence):
     *   1. profile YAML
     *   2. CLI flags (--skip / --only / --reset / --opt=fixture.key=val)
     *   3. interactive prompts (only when -i / --interactive is given)
     */
    private function buildDeployPlan(
        InputInterface $input,
        OutputInterface $output,
        ThemeInterface $theme
    ): DeployPlan {
        $this->aliasResolver->build($theme);

        // ---- 1. Profile defaults --------------------------------------
        $profile = $this->loadProfile($input, $theme, $output);

        $skip = $this->resolveAll($profile['skip'] ?? [], $output);
        $only = isset($profile['only']) ? $this->resolveAll($profile['only'], $output) : null;
        $resetTargets = $this->resolveAll($profile['reset'] ?? [], $output);
        $options = $this->resolveOptionKeys($profile['options'] ?? [], $output);

        // ---- 2. Flag overrides ----------------------------------------
        if ($v = $input->getOption(self::OPT_SKIP)) {
            $skip = $this->resolveAll(
                array_filter(array_map('trim', explode(',', (string) $v))),
                $output
            );
        }
        if ($v = $input->getOption(self::OPT_ONLY)) {
            $only = $this->resolveAll(
                array_filter(array_map('trim', explode(',', (string) $v))),
                $output
            );
        }
        if ($v = $input->getOption(self::OPT_RESET)) {
            $resetTargets = array_filter(array_map('trim', explode(',', (string) $v)));
            // "all" stays special and is expanded below.
            $resetTargets = array_map(
                fn (string $id) => $id === 'all' ? 'all' : ($this->aliasResolver->resolve($id) ?? $id),
                $resetTargets
            );
        }
        // Dot-notation per-fixture options (legacy + escape-hatch)
        foreach ((array) $input->getOption(self::OPT_OPT) as $kv) {
            // <fixture-id>.<key>=<value> — fixture-id may be alias or short name
            if (preg_match('/^([\w-]+)\.([\w-]+)=(.*)$/', (string) $kv, $m)) {
                $resolved = $this->aliasResolver->resolve($m[1]) ?? $m[1];
                $options[$resolved][$m[2]] = $m[3];
            } else {
                $output->writeln(sprintf(
                    '<comment>Ignoring malformed --opt: %s (expected fixture.key=value)</comment>',
                    $kv
                ));
            }
        }
        // Flat per-fixture option flags (e.g. --reviews-per-product=2-5)
        foreach ($this->collectFlatOptions($input, $theme) as $shortName => $opts) {
            foreach ($opts as $key => $value) {
                $options[$shortName][$key] = $value;
            }
        }

        // ---- 3. Wizard layer ------------------------------------------
        // Auto-trigger when no selection/option/profile flag was given
        // AND the input is interactive. Explicit --interactive forces it
        // even when flags are set.
        if ($this->shouldRunWizard($input, $skip, $only, $resetTargets, $options)) {
            [$skip, $only, $resetTargets, $options] =
                $this->runWizard($input, $output, $theme, $skip, $only, $resetTargets, $options);
        }

        // ---- Resolve "all" reset alias --------------------------------
        $allFixtureNames = array_map(
            fn ($cls) => $this->runner->shortName($cls),
            $theme->getFixtures()
        );
        if (in_array('all', $resetTargets, true)) {
            $resetTargets = $allFixtureNames;
        }

        $conflictActions = [];
        foreach ($resetTargets as $name) {
            $conflictActions[$name] = ConflictAction::Reset;
        }

        return new DeployPlan(
            skip: array_values(array_unique($skip)),
            only: $only !== null ? array_values($only) : null,
            conflictActions: $conflictActions,
            options: $options,
            dryRun: (bool) $input->getOption(self::OPT_DRY_RUN),
        );
    }

    /**
     * Translate every entry in a list through the alias resolver,
     * emitting a warning for any unknown identifier.
     *
     * @param array<int, string> $identifiers
     * @return array<int, string>
     */
    private function resolveAll(array $identifiers, OutputInterface $output): array
    {
        $out = [];
        foreach ($identifiers as $id) {
            $shortName = $this->aliasResolver->resolve($id);
            if ($shortName === null) {
                $output->writeln(sprintf(
                    '<comment>Unknown fixture identifier "%s" — left as-is.</comment>',
                    $id
                ));
                $out[] = $id;
                continue;
            }
            $out[] = $shortName;
        }
        return $out;
    }

    /**
     * Translate the keys of a profile's `options` map (which may use
     * aliases) into canonical short class names.
     *
     * @param array<string, array<string, scalar>> $optionsByAlias
     * @return array<string, array<string, scalar>>
     */
    private function resolveOptionKeys(array $optionsByAlias, OutputInterface $output): array
    {
        $out = [];
        foreach ($optionsByAlias as $alias => $opts) {
            $shortName = $this->aliasResolver->resolve($alias) ?? $alias;
            $out[$shortName] = array_merge($out[$shortName] ?? [], $opts);
        }
        return $out;
    }

    /**
     * Walk the registered flat-flag namespace (--<alias>-<key>) and
     * return the values keyed by short class name.
     *
     * @return array<string, array<string, scalar>>
     */
    private function collectFlatOptions(InputInterface $input, ThemeInterface $theme): array
    {
        $out = [];
        foreach ($theme->getFixtures() as $fqcn) {
            if (!is_subclass_of($fqcn, ConfigurableFixtureInterface::class)) {
                continue;
            }
            $shortName = $this->runner->shortName($fqcn);
            $alias = $this->aliasResolver->aliasFor($shortName);
            $schema = $this->describeOptionsFor($fqcn);
            foreach ($schema as $key => $_meta) {
                $flag = sprintf('%s-%s', $alias, $key);
                if (!$input->hasOption($flag)) {
                    continue;
                }
                $value = $input->getOption($flag);
                if ($value === null || $value === '') {
                    continue;
                }
                $out[$shortName][$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Did the user supply ANY selection/option/profile signal? If not
     * AND the input is interactive (TTY, no -n), we drop into the
     * wizard. If --interactive is set explicitly, we always run it.
     *
     * @param array<int, string> $skip
     * @param array<int, string>|null $only
     * @param array<int, string> $reset
     * @param array<string, array<string, scalar>> $options
     */
    private function shouldRunWizard(
        InputInterface $input,
        array $skip,
        ?array $only,
        array $reset,
        array $options
    ): bool {
        if ($input->getOption(self::OPT_INTERACTIVE)) {
            return true;
        }
        if (!$input->isInteractive()) {
            return false;
        }
        // Any flag-driven selection / option present?
        if ($skip !== [] || $only !== null || $reset !== [] || $options !== []) {
            return false;
        }
        if ($input->getOption(self::OPT_PROFILE)) {
            return false;
        }
        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function describeOptionsFor(string $fixtureClass): array
    {
        try {
            $instance = $this->objectManager->create($fixtureClass);
        } catch (\Throwable) {
            return [];
        }
        if (!$instance instanceof ConfigurableFixtureInterface) {
            return [];
        }
        try {
            return $instance->describeOptions();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *     skip?: array<string>,
     *     only?: array<string>,
     *     reset?: array<string>,
     *     options?: array<string, array<string, scalar>>
     * }
     */
    private function loadProfile(
        InputInterface $input,
        ThemeInterface $theme,
        OutputInterface $output
    ): array {
        $name = $input->getOption(self::OPT_PROFILE);
        if (!$name) {
            return [];
        }
        $path = $theme->getFixturesPath() . '/profiles/' . $name . '.yaml';
        if (!is_readable($path)) {
            $output->writeln(sprintf(
                '<comment>Profile "%s" not found at %s — using built-in defaults.</comment>',
                $name,
                $path
            ));
            return [];
        }
        // Light-weight YAML reader: allow simple key:value and key:[a, b] —
        // pulling in symfony/yaml as a hard dep just for profiles felt
        // disproportionate. If profiles grow more complex we can swap in.
        $yaml = @yaml_parse_file($path);
        if (!is_array($yaml)) {
            $output->writeln(sprintf(
                '<comment>Profile "%s" failed to parse (need ext-yaml) — ignored.</comment>',
                $name
            ));
            return [];
        }
        $output->writeln(sprintf('<info>Profile loaded:</info> %s', $name));
        return $yaml;
    }

    /**
     * Wizard: walks the operator through fixture selection, per-fixture
     * conflict actions and per-option configuration. Names are
     * displayed using the curated aliases (reviews, links, simple, …)
     * to match the rest of the CLI.
     *
     * Three sub-prompts:
     *   1. "Run all?" gate — accepting yes returns immediately with
     *      the unchanged skip/only/reset/options arrays. Common case
     *      stays one Enter key.
     *   2. Multi-select fixture picker — only shown if the user said
     *      no to (1).
     *   3. Per-fixture conflict prompt + per-option prompt — shown
     *      only for fixtures that have existing data and/or expose
     *      `describeOptions()`.
     *
     * @param array<string> $skip
     * @param array<string>|null $only
     * @param array<string> $reset
     * @param array<string, array<string, scalar>> $options
     * @return array{0: array<string>, 1: array<string>|null, 2: array<string>, 3: array<string, array<string, scalar>>}
     */
    private function runWizard(
        InputInterface $input,
        OutputInterface $output,
        ThemeInterface $theme,
        array $skip,
        ?array $only,
        array $reset,
        array $options
    ): array {
        $output->writeln('');
        $output->writeln('<info>Wizard:</info> let\'s figure out what to deploy.');
        $output->writeln('');

        $allFixtures = $theme->getFixtures();
        $shortNames = array_map(fn ($cls) => $this->runner->shortName($cls), $allFixtures);
        $aliases = array_map(fn ($n) => $this->aliasResolver->aliasFor($n), $shortNames);

        // 1. Full vs Customised — top-level decision.
        $mode = select(
            label: 'How would you like to deploy?',
            options: [
                'full' => 'Full — run every fixture with default options',
                'customised' => 'Customised — pick fixtures, conflict actions and per-fixture options',
            ],
            default: 'full',
        );
        if ($mode === 'full') {
            $output->writeln('');
            return [$skip, $only, $reset, $options];
        }

        // 2. Multi-select fixture picker (true checkbox UX via laravel/prompts).
        // Build a label → alias map and pre-tick everything not in the
        // current $skip set.
        $optionsMap = [];
        $defaultTicked = [];
        foreach ($aliases as $i => $alias) {
            $existing = $this->maybeCount($allFixtures[$i]);
            $label = $existing !== null && $existing > 0
                ? sprintf('%s (%d existing)', $alias, $existing)
                : $alias;
            $optionsMap[$alias] = $label;
            if (!in_array($shortNames[$i], $skip, true)) {
                $defaultTicked[] = $alias;
            }
        }

        /** @var array<int, string> $selectedAliases */
        $selectedAliases = multiselect(
            label: 'Which fixtures should run? (Space to toggle, Enter to confirm)',
            options: $optionsMap,
            default: $defaultTicked,
            scroll: 15,
            required: false,
            hint: 'Use ↑/↓ to move, Space to toggle, A to toggle all, Enter to confirm.',
        );

        $selectedShortNames = [];
        foreach ($selectedAliases as $a) {
            $resolved = $this->aliasResolver->resolve((string) $a);
            if ($resolved !== null) {
                $selectedShortNames[] = $resolved;
            }
        }
        $only = $selectedShortNames;
        $skip = array_values(array_diff($shortNames, $selectedShortNames));

        // 3. Per-fixture conflict + option prompts for selected fixtures.
        foreach ($allFixtures as $cls) {
            $shortName = $this->runner->shortName($cls);
            if (!in_array($shortName, $selectedShortNames, true)) {
                continue;
            }
            $alias = $this->aliasResolver->aliasFor($shortName);
            $existing = $this->maybeCount($cls);

            if ($existing !== null && $existing > 0) {
                $action = select(
                    label: sprintf('%s already has %d entries — what should I do?', $alias, $existing),
                    options: [
                        'merge' => 'Merge — leave existing, run execute() on top',
                        'reset' => 'Reset — clear() existing first, then re-import',
                        'skip' => 'Skip — leave it untouched, don\'t run',
                    ],
                    default: 'merge',
                );
                if ($action === 'reset') {
                    $reset[] = $shortName;
                } elseif ($action === 'skip') {
                    $skip[] = $shortName;
                    $only = array_values(array_diff($only, [$shortName]));
                    continue;
                }
            }

            // Walk describeOptions() for configurable fixtures and prompt
            // each option with the current value as the default.
            $schema = $this->describeOptionsFor($cls);
            foreach ($schema as $key => $meta) {
                $current = $options[$shortName][$key] ?? ($meta['default'] ?? '');
                $value = text(
                    label: sprintf('%s • %s', $alias, $key),
                    placeholder: is_scalar($current) ? (string) $current : '',
                    default: is_scalar($current) ? (string) $current : '',
                    hint: is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                );
                if ($value !== '' && (string) $value !== (string) $current) {
                    $options[$shortName][$key] = (string) $value;
                }
            }
        }

        return [$skip, $only, $reset, $options];
    }

    /**
     * Best-effort current-count lookup for a fixture. Returns null if
     * the fixture isn't state-aware OR if instantiating it failed.
     */
    private function maybeCount(string $fixtureClass): ?int
    {
        try {
            $instance = $this->objectManager->create($fixtureClass);
        } catch (\Throwable) {
            return null;
        }
        if (!$instance instanceof StateAwareFixtureInterface) {
            return null;
        }
        try {
            return $instance->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function renderPlanPreview(DeployPlan $plan, ThemeInterface $theme, OutputInterface $output): void
    {
        $this->aliasResolver->build($theme);
        $output->writeln('');
        $output->writeln(sprintf('  %-20s  %-9s  %-7s  %s', 'Fixture', 'Existing', 'Action', 'Options'));
        $output->writeln('  ' . str_repeat('─', 70));
        foreach ($theme->getFixtures() as $cls) {
            $shortName = $this->runner->shortName($cls);
            $alias = $this->aliasResolver->aliasFor($shortName);
            $existing = $this->maybeCount($cls);
            $existingStr = $existing !== null ? (string) $existing : '—';
            if (!$plan->shouldRun($shortName)) {
                $action = 'skip';
            } else {
                $action = $existing !== null && $existing > 0
                    ? $plan->conflictAction($shortName)->value
                    : 'run';
            }
            $opts = $plan->optionsFor($shortName);
            $optsStr = $opts === [] ? '' : http_build_query($opts, '', ', ');
            $output->writeln(sprintf('  %-20s  %-9s  %-7s  %s', $alias, $existingStr, $action, $optsStr));
        }
        $output->writeln('');
    }
}
