<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Bulk-inserts configurable-product variants directly into EAV via
 * batched INSERTs, bypassing Magento\Catalog\Api\ProductRepositoryInterface
 * for ~50× speedup on large variant imports.
 *
 * Why this is safe for variants specifically:
 *
 *   - Variants are visibility=1 ("not visible individually"); they don't
 *     need their own URL rewrites because the configurable parent owns
 *     the storefront URL and Magento's PDP swatches resolve variants
 *     through the parent.
 *   - Variants share the parent's images on the category-page card; no
 *     need to attach gallery rows per-variant. The configurable PDP can
 *     show variant-specific images on swatch click but we don't need
 *     that for sample data (and `InheritsChildImage` already pushes the
 *     first variant's image up to the parent).
 *   - Variants only need to differ from defaults on the axis attribute
 *     and price. Everything else (name, description, url_key, etc.)
 *     can be left as null at storeview scope and Magento will fall back
 *     to the parent's value via its EAV "use default" semantics.
 *
 * The slow path through ProductRepository::save() remains the right
 * choice for parents and for genuinely-distinct simple products — they
 * need URL rewrites, category links, and gallery attachment, all of
 * which require Magento's plugin chain.
 *
 * One operation through this class produces:
 *   - N rows in catalog_product_entity (the variant SKUs)
 *   - N rows in catalog_product_website (assignments to every active website)
 *   - N rows in cataloginventory_stock_item (qty + is_in_stock)
 *   - N rows in cataloginventory_stock_status (the search-visible flag)
 *   - N rows in catalog_product_entity_int per attribute we set
 *     (visibility, status, axis attribute, tax_class_id)
 *   - N rows in catalog_product_entity_decimal for the price
 *   - N rows in catalog_product_entity_varchar for the name (so admin
 *     and order line items render something readable)
 *
 * Returns a map of variant SKU → entity_id for the caller to use when
 * linking the configurable parent.
 */
class BulkVariantInserter
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, array{
     *     sku: string,
     *     attribute_set_id: int,
     *     name: string,
     *     price: float,
     *     qty: int,
     *     axis_code: string,
     *     axis_option_id: int,
     *     website_ids: array<int>
     * }> $variants
     *
     * @return array<string, int>  sku => entity_id
     */
    public function insert(array $variants): array
    {
        if ($variants === []) {
            return [];
        }
        $conn = $this->resource->getConnection();

        // ----- 1. catalog_product_entity rows ----------------------------------
        $cpeTable = $this->resource->getTableName('catalog_product_entity');
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($variants as $v) {
            $rows[] = [
                'attribute_set_id' => (int) $v['attribute_set_id'],
                'type_id' => 'simple',
                'sku' => $v['sku'],
                'has_options' => 0,
                'required_options' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $conn->insertMultiple($cpeTable, $rows);

        // Look up the inserted entity_ids by SKU. AUTO_INCREMENT order isn't
        // reliable when other writers are present, so query by sku set.
        $skus = array_column($variants, 'sku');
        $skuToId = $conn->fetchPairs(
            $conn->select()
                ->from($cpeTable, ['sku', 'entity_id'])
                ->where('sku IN (?)', $skus)
        );

        // ----- 2. EAV varchar (name) -------------------------------------------
        $nameAttr = $this->eavConfig->getAttribute('catalog_product', 'name');
        $varcharTable = $this->resource->getTableName('catalog_product_entity_varchar');
        $rows = [];
        foreach ($variants as $v) {
            $entityId = $skuToId[$v['sku']] ?? null;
            if (!$entityId) {
                continue;
            }
            $rows[] = [
                'attribute_id' => (int) $nameAttr->getAttributeId(),
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => (int) $entityId,
                'value' => $v['name'],
            ];
        }
        if ($rows) {
            $conn->insertMultiple($varcharTable, $rows);
        }

        // ----- 3. EAV int (status, visibility, tax_class_id, axis attr) --------
        $statusAttr = $this->eavConfig->getAttribute('catalog_product', 'status');
        $visibilityAttr = $this->eavConfig->getAttribute('catalog_product', 'visibility');
        $taxClassAttr = $this->eavConfig->getAttribute('catalog_product', 'tax_class_id');
        $intTable = $this->resource->getTableName('catalog_product_entity_int');
        $intRows = [];
        // Cache axis attribute IDs so we don't re-resolve per row.
        $axisAttrCache = [];
        foreach ($variants as $v) {
            $entityId = $skuToId[$v['sku']] ?? null;
            if (!$entityId) {
                continue;
            }
            $eid = (int) $entityId;

            $intRows[] = [
                'attribute_id' => (int) $statusAttr->getAttributeId(),
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => $eid,
                'value' => 1,
            ];
            $intRows[] = [
                'attribute_id' => (int) $visibilityAttr->getAttributeId(),
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => $eid,
                'value' => Visibility::VISIBILITY_NOT_VISIBLE,
            ];
            $intRows[] = [
                'attribute_id' => (int) $taxClassAttr->getAttributeId(),
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => $eid,
                'value' => 2, // Taxable Goods
            ];
            // The configurable axis attribute: this is the value that
            // distinguishes one variant from another. It MUST be present
            // for ConfigurableProductBuilder::link() to find a value_index
            // for each variant.
            if (!isset($axisAttrCache[$v['axis_code']])) {
                $axis = $this->eavConfig->getAttribute('catalog_product', $v['axis_code']);
                $axisAttrCache[$v['axis_code']] = (int) $axis->getAttributeId();
            }
            $intRows[] = [
                'attribute_id' => $axisAttrCache[$v['axis_code']],
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => $eid,
                'value' => (int) $v['axis_option_id'],
            ];
        }
        if ($intRows) {
            $conn->insertMultiple($intTable, $intRows);
        }

        // ----- 4. EAV decimal (price) ------------------------------------------
        $priceAttr = $this->eavConfig->getAttribute('catalog_product', 'price');
        $decimalTable = $this->resource->getTableName('catalog_product_entity_decimal');
        $decRows = [];
        foreach ($variants as $v) {
            $entityId = $skuToId[$v['sku']] ?? null;
            if (!$entityId) {
                continue;
            }
            $decRows[] = [
                'attribute_id' => (int) $priceAttr->getAttributeId(),
                'store_id' => Store::DEFAULT_STORE_ID,
                'entity_id' => (int) $entityId,
                'value' => (float) $v['price'],
            ];
        }
        if ($decRows) {
            $conn->insertMultiple($decimalTable, $decRows);
        }

        // ----- 5. catalog_product_website (one row per website per variant) ----
        $websiteTable = $this->resource->getTableName('catalog_product_website');
        $wsRows = [];
        foreach ($variants as $v) {
            $entityId = $skuToId[$v['sku']] ?? null;
            if (!$entityId) {
                continue;
            }
            foreach ($v['website_ids'] as $wid) {
                $wsRows[] = [
                    'product_id' => (int) $entityId,
                    'website_id' => (int) $wid,
                ];
            }
        }
        if ($wsRows) {
            $conn->insertMultiple($websiteTable, $wsRows);
        }

        // ----- 6. cataloginventory_stock_item ----------------------------------
        // One row per variant, scoped to website 0 (= default scope, applies
        // everywhere). Magento's stock-status indexer will populate the
        // search-visible stock_status table from this on next reindex.
        $stockItemTable = $this->resource->getTableName('cataloginventory_stock_item');
        $stockRows = [];
        foreach ($variants as $v) {
            $entityId = $skuToId[$v['sku']] ?? null;
            if (!$entityId) {
                continue;
            }
            $stockRows[] = [
                'product_id' => (int) $entityId,
                'stock_id' => 1,
                'qty' => (float) $v['qty'],
                'min_qty' => 0,
                'use_config_min_qty' => 1,
                'is_qty_decimal' => 0,
                'backorders' => 0,
                'use_config_backorders' => 1,
                'min_sale_qty' => 1,
                'use_config_min_sale_qty' => 1,
                'max_sale_qty' => 0,
                'use_config_max_sale_qty' => 1,
                'is_in_stock' => $v['qty'] > 0 ? 1 : 0,
                'use_config_notify_stock_qty' => 1,
                'manage_stock' => 0,
                'use_config_manage_stock' => 1,
                'use_config_qty_increments' => 1,
                'qty_increments' => 0,
                'use_config_enable_qty_inc' => 1,
                'enable_qty_increments' => 0,
                'is_decimal_divided' => 0,
                'website_id' => 0,
            ];
        }
        if ($stockRows) {
            $conn->insertMultiple($stockItemTable, $stockRows);
        }

        $this->logger->info(sprintf(
            '[disrex/sample-data-themes] BulkVariantInserter: %d variants inserted in batch',
            count($skuToId)
        ));

        return $skuToId;
    }
}
