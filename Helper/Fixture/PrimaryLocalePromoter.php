<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Psr\Log\LoggerInterface;

/**
 * Promotes a non-default locale onto the storefront's *default* storeview
 * so a fresh `composer require` + `sampledata:theme:deploy` produces a
 * working demo in that locale without any manual admin work.
 *
 * The fixtures themselves keep writing per-storeview data correctly
 * (English on store 1, Dutch on store 4, etc). Promoting one locale
 * means: copy that locale's storeview EAV onto the default storeview,
 * flip the default storeview's locale + currency, and regenerate URL
 * rewrites so plain `/woonkamer.html` resolves the way `/nl/woonkamer.html`
 * does.
 *
 * Idempotent: running the promotion twice with the same locale is a
 * no-op. The overlay is keyed on (entity, store, attribute) so the
 * second pass overwrites the same rows with identical values.
 *
 * Reversible: pointing the promoter at a different locale (or at the
 * theme's `default-locale`) overlays the new locale's data on top of
 * what's there. Nothing is destructively deleted from other storeviews.
 */
class PrimaryLocalePromoter
{
    private const COUNTRY_CURRENCY = [
        'NL' => 'EUR', 'BE' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR',
        'IT' => 'EUR', 'ES' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR',
        'IE' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR',
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD',
        'AU' => 'AUD', 'NZ' => 'NZD', 'CH' => 'CHF',
        'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
        'PL' => 'PLN', 'JP' => 'JPY',
    ];

    /**
     * Default storeview id Magento ships with on a stock install. When an
     * operator has renumbered or rebuilt their storeviews, they should
     * pass an explicit `--primary-store-id` instead of relying on this.
     */
    private const DEFAULT_STORE_ID = 1;

    public function __construct(
        private readonly LocaleResolver $localeResolver,
        private readonly ResourceConnection $resourceConnection,
        private readonly ConfigWriter $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly UrlPersistInterface $urlPersist,
        private readonly CategoryUrlRewriteGenerator $categoryUrlGen,
        private readonly ProductUrlRewriteGenerator $productUrlGen,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Promote $locale onto the default storeview ($targetStoreId).
     *
     * @param string $skuPrefix Restricts product overlay + rewrite
     *                          regeneration to SKUs starting with this
     *                          prefix. Themes pass their own prefix
     *                          (e.g. "DRX-HL-") to avoid stomping on
     *                          unrelated catalog data.
     */
    public function promote(
        string $locale,
        string $skuPrefix,
        ?int $targetStoreId = null
    ): PromoteResult {
        $targetStoreId = $targetStoreId ?? self::DEFAULT_STORE_ID;
        $sourceStoreIds = $this->localeResolver->resolveStoreviewIds($locale);
        if ($sourceStoreIds === []) {
            return PromoteResult::failed(sprintf(
                'No storeview is configured with locale "%s"; cannot promote.',
                $locale
            ));
        }
        // First storeview wins when a locale spans more than one (e.g.
        // nl_NL on a `nl` store and a `be_nl` store).
        $sourceStoreId = (int) $sourceStoreIds[0];

        // When source == target, the EAV overlay would be a self-copy
        // no-op (every row's source value is already its target value).
        // But the rewrite table can still be stale from a prior deploy:
        // category rewrites generated *during* fixture import use whatever
        // url_key was in admin scope at that moment, and a re-deploy
        // doesn't refresh them. So we always re-run the rewrite step,
        // even on the skip path, so the storefront URLs stay in sync
        // with the latest CSV-driven url_keys.
        $skipOverlay = ($sourceStoreId === $targetStoreId);

        $this->writeLocaleConfig($locale, $targetStoreId);
        $rows = $skipOverlay ? 0 : $this->overlayProducts($sourceStoreId, $targetStoreId, $skuPrefix);
        $catRows = $skipOverlay ? 0 : $this->overlayCategories($sourceStoreId, $targetStoreId);
        $optRows = $skipOverlay ? 0 : $this->overlayAttributeOptions($sourceStoreId, $targetStoreId);
        $labelRows = $skipOverlay ? 0 : $this->overlayAttributeLabels($sourceStoreId, $targetStoreId);
        [$catRewrites, $productRewrites] = $this->regenerateRewrites($targetStoreId, $skuPrefix);

        $this->cacheTypeList->cleanType('config');
        $this->cacheTypeList->cleanType('block_html');
        $this->cacheTypeList->cleanType('full_page');

        return PromoteResult::ok([
            'source_store_id' => $sourceStoreId,
            'target_store_id' => $targetStoreId,
            'product_rows' => $rows,
            'category_rows' => $catRows,
            'option_rows' => $optRows,
            'label_rows' => $labelRows,
            'category_rewrites' => $catRewrites,
            'product_rewrites' => $productRewrites,
        ]);
    }

    private function writeLocaleConfig(string $locale, int $targetStoreId): void
    {
        $this->configWriter->save('general/locale/code', $locale, 'stores', $targetStoreId);
        if (preg_match('/^[a-z]{2}_([A-Z]{2})$/', $locale, $m)) {
            $currency = self::COUNTRY_CURRENCY[$m[1]] ?? null;
            if ($currency !== null) {
                $this->configWriter->save(
                    'currency/options/default',
                    $currency,
                    'stores',
                    $targetStoreId
                );
            }
        }
    }

    private function overlayProducts(int $sourceStoreId, int $targetStoreId, string $skuPrefix): int
    {
        $connection = $this->resourceConnection->getConnection();
        $rowCount = 0;
        foreach (['catalog_product_entity_varchar', 'catalog_product_entity_text'] as $table) {
            $tbl = $this->resourceConnection->getTableName($table);
            $entityTbl = $this->resourceConnection->getTableName('catalog_product_entity');
            $select = $connection->select()
                ->from(['v' => $tbl], ['attribute_id', 'entity_id', 'value'])
                ->joinInner(['e' => $entityTbl], 'e.entity_id = v.entity_id', [])
                ->where('v.store_id = ?', $sourceStoreId)
                ->where('e.sku LIKE ?', $skuPrefix . '%');

            $rows = $connection->fetchAll($select);
            foreach ($rows as $row) {
                $connection->insertOnDuplicate($tbl, [
                    'attribute_id' => (int) $row['attribute_id'],
                    'store_id' => $targetStoreId,
                    'entity_id' => (int) $row['entity_id'],
                    'value' => $row['value'],
                ], ['value']);
                $rowCount++;
            }
        }
        return $rowCount;
    }

    private function overlayCategories(int $sourceStoreId, int $targetStoreId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $rowCount = 0;
        foreach (['catalog_category_entity_varchar', 'catalog_category_entity_text'] as $table) {
            $tbl = $this->resourceConnection->getTableName($table);
            $select = $connection->select()
                ->from(['v' => $tbl], ['attribute_id', 'entity_id', 'value'])
                ->where('v.store_id = ?', $sourceStoreId)
                ->where('v.entity_id > ?', 2);

            $rows = $connection->fetchAll($select);
            foreach ($rows as $row) {
                $connection->insertOnDuplicate($tbl, [
                    'attribute_id' => (int) $row['attribute_id'],
                    'store_id' => $targetStoreId,
                    'entity_id' => (int) $row['entity_id'],
                    'value' => $row['value'],
                ], ['value']);
                $rowCount++;
            }
        }
        return $rowCount;
    }

    private function overlayAttributeOptions(int $sourceStoreId, int $targetStoreId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tbl = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['ov' => $tbl], ['option_id', 'value'])
                ->where('ov.store_id = ?', $sourceStoreId)
        );
        foreach ($rows as $row) {
            $connection->insertOnDuplicate($tbl, [
                'option_id' => (int) $row['option_id'],
                'store_id' => $targetStoreId,
                'value' => $row['value'],
            ], ['value']);
        }
        return count($rows);
    }

    private function overlayAttributeLabels(int $sourceStoreId, int $targetStoreId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tbl = $this->resourceConnection->getTableName('eav_attribute_label');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['l' => $tbl], ['attribute_id', 'value'])
                ->where('l.store_id = ?', $sourceStoreId)
        );
        foreach ($rows as $row) {
            $connection->insertOnDuplicate($tbl, [
                'attribute_id' => (int) $row['attribute_id'],
                'store_id' => $targetStoreId,
                'value' => $row['value'],
            ], ['value']);
        }
        return count($rows);
    }

    /**
     * @return array{0:int, 1:int} [category-rewrite count, product-rewrite count]
     */
    private function regenerateRewrites(int $targetStoreId, string $skuPrefix): array
    {
        // Drop any existing rewrites for this storeview that point at our
        // entities, so stale English slugs don't co-exist with the new
        // localized ones (duplicate-content / 404 risk).
        $connection = $this->resourceConnection->getConnection();
        $rewriteTbl = $this->resourceConnection->getTableName('url_rewrite');
        $connection->delete($rewriteTbl, [
            'store_id = ?' => $targetStoreId,
            'entity_type IN (?)' => ['category', 'product'],
        ]);

        $catRewrites = 0;
        $catCollection = $this->categoryCollectionFactory->create()
            ->setStoreId($targetStoreId)
            ->addAttributeToSelect(['url_key', 'url_path', 'name', 'is_active'])
            ->addFieldToFilter('path', ['like' => '1/2/%']);
        foreach ($catCollection as $cat) {
            $cat->setStoreId($targetStoreId);
            $rewrites = $this->categoryUrlGen->generate($cat);
            if ($rewrites) {
                try {
                    $this->urlPersist->replace($rewrites);
                    $catRewrites += count($rewrites);
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf(
                        '[disrex/sample-data-themes] Category rewrite skipped for id %d: %s',
                        $cat->getId(),
                        $e->getMessage()
                    ));
                }
            }
        }

        $productRewrites = 0;
        $prodCollection = $this->productCollectionFactory->create()
            ->setStoreId($targetStoreId)
            ->addAttributeToSelect(['url_key', 'name', 'visibility'])
            ->addFieldToFilter('sku', ['like' => $skuPrefix . '%'])
            ->addAttributeToFilter('visibility', 4);
        foreach ($prodCollection as $product) {
            $product->setStoreId($targetStoreId);
            $rewrites = $this->productUrlGen->generate($product);
            if ($rewrites) {
                try {
                    $this->urlPersist->replace($rewrites);
                    $productRewrites += count($rewrites);
                } catch (\Throwable $e) {
                    // Duplicate request_path is the common case here when
                    // two SKUs collide on a generated url_key — log and
                    // move on rather than aborting the whole pass.
                    $this->logger->warning(sprintf(
                        '[disrex/sample-data-themes] Product rewrite skipped for sku %s: %s',
                        $product->getSku(),
                        $e->getMessage()
                    ));
                }
            }
        }

        return [$catRewrites, $productRewrites];
    }
}
