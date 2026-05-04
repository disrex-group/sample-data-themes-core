<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Maps Magento storeviews to their configured locale codes
 * (`general/locale/code`). Used by the framework to decide which storeview
 * receives translations for a given locale CSV.
 *
 * One locale may be configured on more than one storeview (for example,
 * `nl_NL` on both a `nl` and `be_nl` storeview); this class returns *all*
 * matching storeviews so translations land everywhere they should.
 */
class LocaleResolver
{
    private const CONFIG_LOCALE = 'general/locale/code';

    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array<string, array<int, int>> map: locale => list of store ids
     */
    public function getStoreviewsByLocale(): array
    {
        $map = [];
        foreach ($this->storeRepository->getList() as $store) {
            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                // Admin scope — never a frontend storeview.
                continue;
            }
            $locale = (string) $this->scopeConfig->getValue(
                self::CONFIG_LOCALE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            if ($locale === '') {
                continue;
            }
            $map[$locale][] = $storeId;
        }
        return $map;
    }

    /**
     * @return array<int, int> Storeview ids that have $locale configured.
     */
    public function resolveStoreviewIds(string $locale): array
    {
        return $this->getStoreviewsByLocale()[$locale] ?? [];
    }

    public function hasStoreviewForLocale(string $locale): bool
    {
        return $this->resolveStoreviewIds($locale) !== [];
    }
}
