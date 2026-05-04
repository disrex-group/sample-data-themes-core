<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Disrex\SampleDataThemesCore\Exception\MissingStoreviewException;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\WebsiteFactory;

/**
 * Detects whether a storeview exists for a given locale and, when asked,
 * creates a sane default (one website + one group + one store) per missing
 * locale.
 *
 * The defaults derived from a locale code `xx_YY` are:
 *
 *   website code: lower-case `yy`     name: upper-case `YY`
 *   group   code: `yy_main`           name: `YY Store`
 *   store   code: lower-case `yy`     name: derived from country code
 *
 * Production-style demos should configure storeviews manually; the
 * auto-create flow exists to make `bin/magento sampledata:theme:deploy
 * --auto-create-storeviews` Just Work on a fresh dev install.
 */
class StoreviewManager
{
    private const CONFIG_LOCALE = 'general/locale/code';
    private const CONFIG_CURRENCY_DEFAULT = 'currency/options/default';
    private const CONFIG_CURRENCY_BASE = 'currency/options/base';
    private const CONFIG_CURRENCY_ALLOW = 'currency/options/allow';

    /**
     * Country → ISO-4217 currency. Keep narrow: only the locales the
     * sample-data themes ship by default. Operators with bespoke
     * locales should set currency manually or extend this map.
     */
    private const COUNTRY_CURRENCY = [
        'NL' => 'EUR', 'BE' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR',
        'IT' => 'EUR', 'ES' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR',
        'IE' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR',
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD',
        'AU' => 'AUD', 'NZ' => 'NZD', 'CH' => 'CHF',
        'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
        'PL' => 'PLN', 'JP' => 'JPY',
    ];

    public function __construct(
        private readonly LocaleResolver $localeResolver,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly WebsiteFactory $websiteFactory,
        private readonly GroupFactory $groupFactory,
        private readonly StoreFactory $storeFactory,
        private readonly ConfigWriter $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Ensure at least one storeview exists for $locale, and apply the
     * theme's intended locale + currency config to every matching
     * storeview each run (idempotent — only writes when the value
     * differs from what's already stored).
     *
     * @return array<int, int> The storeview IDs that match.
     *
     * @throws MissingStoreviewException If the locale has no storeview and
     *                                   $autoCreate is false.
     */
    public function ensureStoreviewForLocale(string $locale, bool $autoCreate = false): array
    {
        $existing = $this->localeResolver->resolveStoreviewIds($locale);
        if ($existing === []) {
            if (!$autoCreate) {
                throw MissingStoreviewException::forLocale($locale);
            }
            $existing = [$this->createStoreviewForLocale($locale)];
        }

        $this->applyStoreviewConfig($locale, $existing);

        return $existing;
    }

    /**
     * Reapply locale + currency to every storeview attached to $locale.
     * Called on every deploy so that storefronts always render in their
     * declared language and currency, even if an operator changed the
     * config in admin between runs.
     *
     * @param array<int, int> $storeIds
     */
    public function applyStoreviewConfig(string $locale, array $storeIds): void
    {
        if ($storeIds === []) {
            return;
        }
        if (!preg_match('/^([a-z]{2})_([A-Z]{2})$/', $locale, $m)) {
            return;
        }
        $countryCode = $m[2];
        $currency = self::COUNTRY_CURRENCY[$countryCode] ?? null;

        $touched = false;
        foreach ($storeIds as $storeId) {
            $touched = $this->writeIfChanged(self::CONFIG_LOCALE, $locale, 'stores', (int) $storeId) || $touched;
            if ($currency !== null) {
                $touched = $this->writeIfChanged(
                    self::CONFIG_CURRENCY_DEFAULT,
                    $currency,
                    'stores',
                    (int) $storeId
                ) || $touched;
            }
        }

        // Currency base + allow list lives at the website scope. Set both
        // once per website that owns any of the storeIds.
        if ($currency !== null) {
            $websiteIds = $this->websiteIdsForStores($storeIds);
            foreach ($websiteIds as $websiteId) {
                $touched = $this->writeIfChanged(
                    self::CONFIG_CURRENCY_BASE,
                    $currency,
                    'websites',
                    $websiteId
                ) || $touched;
                $existingAllow = $this->existingAllowList($websiteId);
                if (!in_array($currency, $existingAllow, true)) {
                    $existingAllow[] = $currency;
                    $touched = $this->writeIfChanged(
                        self::CONFIG_CURRENCY_ALLOW,
                        implode(',', $existingAllow),
                        'websites',
                        $websiteId
                    ) || $touched;
                }
            }
        }

        if ($touched) {
            $this->cacheTypeList->cleanType('config');
        }
    }

    /**
     * Return the unique set of website ids backing the given store ids.
     *
     * @param array<int, int> $storeIds
     * @return array<int, int>
     */
    private function websiteIdsForStores(array $storeIds): array
    {
        $ids = [];
        foreach ($storeIds as $storeId) {
            try {
                $store = $this->storeFactory->create();
                $store->load((int) $storeId);
                if ($store->getId()) {
                    $ids[(int) $store->getWebsiteId()] = true;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return array_keys($ids);
    }

    /**
     * @return array<int, string>
     */
    private function existingAllowList(int $websiteId): array
    {
        $current = (string) $this->scopeConfig->getValue(
            self::CONFIG_CURRENCY_ALLOW,
            'websites',
            $websiteId
        );
        if ($current === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $current)));
    }

    private function writeIfChanged(string $path, string $value, string $scope, int $scopeId): bool
    {
        $current = (string) $this->scopeConfig->getValue($path, $scope, $scopeId);
        if ($current === $value) {
            return false;
        }
        $this->configWriter->save($path, $value, $scope, $scopeId);
        return true;
    }

    private function createStoreviewForLocale(string $locale): int
    {
        if (!preg_match('/^([a-z]{2})_([A-Z]{2})$/', $locale, $m)) {
            throw new \InvalidArgumentException(sprintf(
                'Locale "%s" is not in the expected ICU "xx_YY" form.',
                $locale
            ));
        }
        $langCode = $m[1];
        $countryCode = $m[2];

        $website = $this->getOrCreateWebsite($countryCode);
        $group = $this->getOrCreateGroup($website, $countryCode);
        $store = $this->createStore($group, $website, $langCode, $locale);

        $this->configWriter->save(
            self::CONFIG_LOCALE,
            $locale,
            'stores',
            (int) $store->getId()
        );

        $this->cacheTypeList->cleanType('config');

        return (int) $store->getId();
    }

    private function getOrCreateWebsite(string $countryCode): WebsiteInterface
    {
        $code = strtolower($countryCode);
        try {
            return $this->websiteRepository->get($code);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            // Fall through and create.
        }

        $website = $this->websiteFactory->create();
        $website->setCode($code);
        $website->setName(strtoupper($countryCode));
        $website->setDefaultGroupId(0);
        $website->save();

        return $this->websiteRepository->get($code);
    }

    private function getOrCreateGroup(WebsiteInterface $website, string $countryCode): GroupInterface
    {
        $code = strtolower($countryCode) . '_main';
        foreach ($this->groupRepository->getList() as $group) {
            if ($group->getCode() === $code) {
                return $group;
            }
        }

        $group = $this->groupFactory->create();
        $group->setCode($code);
        $group->setName(strtoupper($countryCode) . ' Store');
        $group->setWebsiteId((int) $website->getId());
        $group->setRootCategoryId(2);  // The default root category in stock Magento.
        $group->save();

        // Re-fetch via the repository to have the canonical instance.
        foreach ($this->groupRepository->getList() as $reloaded) {
            if ($reloaded->getCode() === $code) {
                return $reloaded;
            }
        }
        return $group;
    }

    private function createStore(
        GroupInterface $group,
        WebsiteInterface $website,
        string $langCode,
        string $locale
    ): StoreInterface {
        $store = $this->storeFactory->create();
        $store->setCode($langCode);
        $store->setName(sprintf('%s (%s)', strtoupper($langCode), $locale));
        $store->setWebsiteId((int) $website->getId());
        $store->setGroupId((int) $group->getId());
        $store->setIsActive(1);
        $store->save();

        return $store;
    }
}
