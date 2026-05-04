<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model\Theme;

use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Convenience base class for theme implementations. Subclasses set the
 * declarative properties below; everything else is derived.
 *
 * Subclasses should declare:
 *
 *     protected const CODE        = 'home-living';
 *     protected const NAME        = 'Home & Living';
 *     protected const DESCRIPTION = 'Scandinavian-inspired home & living';
 *     protected const VERSION     = '1.0.0';
 *     protected const MODULE_NAME = 'Disrex_SampleDataThemeHomeLiving';
 *     protected const FIXTURES    = [SimpleProductFixture::class, ...];
 *     protected const LOCALES     = ['en_US', 'nl_NL'];
 *     protected const DEFAULT_LOCALE = 'en_US';
 */
abstract class AbstractTheme implements ThemeInterface
{
    protected const CODE = '';
    protected const NAME = '';
    protected const DESCRIPTION = '';
    protected const VERSION = '0.0.0';
    protected const MODULE_NAME = '';
    /** @var array<int, class-string<\Disrex\SampleDataThemesCore\Api\FixtureInterface>> */
    protected const FIXTURES = [];
    /** @var array<int, string> */
    protected const LOCALES = ['en_US'];
    protected const DEFAULT_LOCALE = 'en_US';
    protected const SKU_PREFIX = '';
    /** @var array<int, string> */
    protected const OPTIONAL_DEPENDENCIES = [];

    public function __construct(
        private readonly ModuleDirReader $moduleReader
    ) {
    }

    public function getCode(): string
    {
        return static::CODE;
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getDescription(): string
    {
        return static::DESCRIPTION;
    }

    public function getFixtures(): array
    {
        return static::FIXTURES;
    }

    public function getFixturesPath(): string
    {
        if (static::MODULE_NAME === '') {
            throw new \LogicException(sprintf(
                'Theme %s must declare MODULE_NAME.',
                static::class
            ));
        }
        return $this->moduleReader->getModuleDir('', static::MODULE_NAME) . '/_files';
    }

    public function getOptionalDependencies(): array
    {
        return static::OPTIONAL_DEPENDENCIES;
    }

    public function getSupportedLocales(): array
    {
        return static::LOCALES;
    }

    public function getDefaultLocale(): string
    {
        return static::DEFAULT_LOCALE;
    }

    public function getVersion(): string
    {
        return static::VERSION;
    }

    public function getSkuPrefix(): string
    {
        return static::SKU_PREFIX;
    }
}
