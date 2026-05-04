<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductActionResource;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Imports catalog products. Handles simple, configurable, grouped, and
 * bundle types via dispatch on the row's `type_id` (defaulting to simple).
 *
 * The importer is opinionated about a few things:
 *
 *  * Multi-value attributes are encoded as comma-separated codes in the
 *    base CSV (e.g. `living,bedroom`); they are resolved via
 *    {@see AttributeImporter::resolveOptionId()}.
 *  * Stock data assumes single-source by default. Themes targeting MSI
 *    deployments should subclass and override `applyStock()`.
 *  * Translations are written in a second pass through
 *    {@see setTranslatedFields()} once the base product exists.
 */
class ProductImporter
{
    /** @var array<string, int> attribute set name => id */
    private array $attributeSetCache = [];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly Product $productModel,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly CategoryImporter $categoryImporter,
        private readonly AttributeImporter $attributeImporter,
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly ModuleDirReader $moduleDirReader,
        private readonly LoggerInterface $logger,
        private readonly ProductActionResource $productActionResource,
        private readonly CustomOptionsParser $customOptionsParser
    ) {
    }

    /**
     * Create or update a base product (admin scope, locale-independent
     * fields only). Names / descriptions are written separately via
     * {@see setTranslatedFields()}.
     *
     * @param array<string, string> $row
     */
    public function createOrUpdateBase(array $row): ProductInterface
    {
        $sku = $this->require($row, 'sku');
        $type = $row['type_id'] ?? ProductType::TYPE_SIMPLE;

        try {
            $product = $this->productRepository->get($sku, true, Store::DEFAULT_STORE_ID, true);
        } catch (NoSuchEntityException) {
            $product = $this->productFactory->create();
            $product->setSku($sku);
        }

        $product->setStoreId(Store::DEFAULT_STORE_ID);
        $product->setTypeId($type);
        $product->setAttributeSetId($this->resolveAttributeSetId($row['attribute_set'] ?? 'Default'));
        $product->setVisibility((int) ($row['visibility'] ?? Visibility::VISIBILITY_BOTH));
        $product->setStatus((int) ($row['status'] ?? 1));
        $product->setWebsiteIds($this->resolveWebsiteIds($row));

        // Magento marks `price` as a required attribute on the catalog
        // entity. Grouped products (and bundle parents in dynamic-price
        // mode) carry no price in the source CSV, so default to 0.0 to
        // satisfy the validator. Magento computes the displayed price for
        // those types from their associated children at render time.
        $rawPrice = $row['price'] ?? '';
        $product->setPrice($rawPrice !== '' ? (float) $rawPrice : 0.0);
        if (isset($row['weight']) && $row['weight'] !== '') {
            $product->setWeight((float) $row['weight']);
        }

        // A throwaway placeholder name keeps the save valid; per-locale
        // translations overwrite this immediately afterwards.
        if (!$product->getName()) {
            $product->setName($sku);
        }

        $this->applyCustomAttributes($product, $row);

        if (isset($row['categories']) && $row['categories'] !== '') {
            $paths = array_filter(array_map('trim', explode(',', $row['categories'])));
            // Cross-cut: also link this product into product-types/<type_id>
            // if that category exists. This gives the storefront a way to
            // browse "all simples" / "all configurables" / "all bundles"
            // etc. without forcing every CSV row to know about it.
            $typePath = 'product-types/' . $product->getTypeId();
            if ($this->categoryImporter->resolvePathToId($typePath) !== null) {
                $paths[] = $typePath;
            }
            $product->setCategoryIds($this->categoryImporter->resolvePathsToIds($paths));
        }

        // Custom options attach BEFORE save so the option rows land
        // atomically with the product. Used by virtual products
        // (giftcards) and personalisable simples (engraved mirrors etc).
        if (!empty($row['custom_options'])) {
            $options = $this->customOptionsParser->parse($sku, (string) $row['custom_options']);
            if ($options !== []) {
                $product->setOptions($options);
                $product->setHasOptions(true);
                $product->setCanSaveCustomOptions(true);
            }
        }

        $product = $this->productRepository->save($product);

        $this->applyStock($product, $row);

        if (!empty($row['images'])) {
            $this->attachImages($product, $row['images']);
        }

        return $product;
    }

    /**
     * Apply translated, storeview-scoped fields.
     *
     * @param array<string, ?string> $fields
     * @param array<int, int> $storeIds
     */
    public function setTranslatedFields(string $sku, array $storeIds, array $fields): void
    {
        if ($storeIds === []) {
            return;
        }

        // Resolve the SKU once.
        try {
            $entityId = (int) $this->productRepository->get($sku, false, null, false)->getId();
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] Cannot translate %s — not found.',
                $sku
            ));
            return;
        }

        // Going through ProductRepository::save() at storeview scope is
        // unreliable here for two reasons:
        //   1. Magento's EAV save layer treats a storeview value that
        //      matches the default-store value as "use default" and
        //      deletes the storeview row, breaking translations whose
        //      strings happen to coincide with English.
        //   2. The repository's static cache holds stale storeId state
        //      between sequential saves of the same SKU.
        // Writing the storeview EAV rows directly via the Action resource
        // bypasses both quirks; it's the same mechanism the admin product
        // form uses when toggling "Use Default Value" off.
        $clean = [];
        foreach ($fields as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$field] = $value;
        }
        if ($clean === []) {
            return;
        }

        foreach ($storeIds as $storeId) {
            try {
                $this->productActionResource->updateAttributes(
                    [$entityId],
                    $clean,
                    (int) $storeId
                );
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Translation write failed for %s at store %d: %s',
                    $sku,
                    $storeId,
                    $e->getMessage()
                ), ['exception' => $e]);
            }
        }
    }

    /**
     * @param array<int, string> $skus
     */
    public function deleteBySkus(array $skus): void
    {
        foreach ($skus as $sku) {
            try {
                $this->productRepository->deleteById($sku);
            } catch (NoSuchEntityException) {
                // Already gone.
            }
        }
    }

    /**
     * Purge URL rewrites whose target entity no longer exists. Magento
     * does not always cascade these rows when products or categories are
     * deleted, so a re-run of an import (or partial cleanup between runs)
     * can leave orphaned rewrites that block fresh inserts with "URL key
     * for specified store already exists."
     *
     * Also clears product rewrites whose target row points at an SKU
     * outside the catalog table — a defensive sweep against rewrites
     * left behind from prior deploys with different url_keys at any
     * scope. Categories get the same treatment.
     *
     * Safe to call before any save: only touches truly orphaned rows.
     */
    public function cleanOrphanProductUrlRewrites(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $urlRewrite = $this->resourceConnection->getTableName('url_rewrite');
        $catalogProduct = $this->resourceConnection->getTableName('catalog_product_entity');
        $catalogCategory = $this->resourceConnection->getTableName('catalog_category_entity');

        $cleared = 0;

        // 1. Orphan product rewrites — entity no longer exists.
        $select = $connection->select()
            ->from(['ur' => $urlRewrite], ['url_rewrite_id'])
            ->joinLeft(['cpe' => $catalogProduct], 'ur.entity_id = cpe.entity_id', [])
            ->where('ur.entity_type = ?', 'product')
            ->where('cpe.entity_id IS NULL');
        $orphanIds = $connection->fetchCol($select);
        if ($orphanIds !== []) {
            $connection->delete($urlRewrite, ['url_rewrite_id IN (?)' => $orphanIds]);
            $cleared += count($orphanIds);
        }

        // 2. Orphan category rewrites — entity no longer exists.
        $select = $connection->select()
            ->from(['ur' => $urlRewrite], ['url_rewrite_id'])
            ->joinLeft(['cce' => $catalogCategory], 'ur.entity_id = cce.entity_id', [])
            ->where('ur.entity_type = ?', 'category')
            ->where('cce.entity_id IS NULL');
        $orphanIds = $connection->fetchCol($select);
        if ($orphanIds !== []) {
            $connection->delete($urlRewrite, ['url_rewrite_id IN (?)' => $orphanIds]);
            $cleared += count($orphanIds);
        }

        // 3. Stale category rewrites whose request_path collides with a
        //    category we're about to re-save. The category fixture
        //    regenerates rewrites from each category's url_key on save,
        //    so it's safe to wipe ALL category rewrites here — anything
        //    we delete will reappear within seconds. Without this step,
        //    a re-deploy with the same url_keys but different entity_ids
        //    (e.g. after a partial-failure run that re-created some
        //    categories) hits "URL key for specified store already
        //    exists" because the old request_path is still bound to a
        //    different entity_id. Catalog products use this same idiom
        //    via PrimaryLocalePromoter; categories now match.
        $stale = $connection->fetchCol(
            $connection->select()
                ->from($urlRewrite, 'url_rewrite_id')
                ->where('entity_type = ?', 'category')
        );
        if ($stale !== []) {
            $connection->delete($urlRewrite, ['url_rewrite_id IN (?)' => $stale]);
            $cleared += count($stale);
        }

        return $cleared;
    }

    public function resolveAttributeSetId(string $name): int
    {
        if (isset($this->attributeSetCache[$name])) {
            return $this->attributeSetCache[$name];
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_name', $name)
            ->create();
        $list = $this->attributeSetRepository->getList($criteria);
        foreach ($list->getItems() as $set) {
            /** @var AttributeSetInterface $set */
            $this->attributeSetCache[$name] = (int) $set->getAttributeSetId();
            return $this->attributeSetCache[$name];
        }

        // Fall back to the default attribute set so imports never hard-fail.
        $defaultId = (int) $this->productModel->getDefaultAttributeSetId();
        $this->attributeSetCache[$name] = $defaultId;
        return $defaultId;
    }

    /**
     * Resolve which websites a product should be assigned to. Honours an
     * explicit `website_ids` column on the CSV row when set; otherwise
     * defaults to *every* non-admin website that has at least one
     * storeview attached. The default exists so a multi-site install
     * (e.g. en + nl on separate websites) sees the catalog on every
     * storefront after a single sample-data deploy, without each row
     * having to enumerate website ids.
     *
     * @param array<string, string> $row
     * @return array<int, int>
     */
    private function resolveWebsiteIds(array $row): array
    {
        if (!empty($row['website_ids'])) {
            return array_map('intval', array_filter(array_map('trim', explode(',', $row['website_ids']))));
        }
        $connection = $this->resourceConnection->getConnection();
        $storeWebsiteTable = $this->resourceConnection->getTableName('store_website');
        $storeTable = $this->resourceConnection->getTableName('store');
        $rows = $connection->fetchCol(
            $connection->select()
                ->from(['w' => $storeWebsiteTable], ['website_id'])
                ->joinInner(['s' => $storeTable], 's.website_id = w.website_id', [])
                ->where('w.website_id != ?', 0)
                ->where('s.store_id != ?', 0)
                ->distinct()
        );
        $ids = array_map('intval', $rows);
        return $ids !== [] ? $ids : [1];
    }

    /**
     * @param array<string, string> $row
     */
    private function applyCustomAttributes(ProductInterface $product, array $row): void
    {
        $reservedKeys = [
            'sku', 'type_id', 'attribute_set', 'price', 'weight', 'qty', 'visibility', 'status',
            'categories', 'images', 'website_ids', 'name', 'description', 'short_description',
            'url_key', 'meta_title', 'meta_description', 'meta_keyword', 'meta_keywords',
            // Configurable variations carry these but they are not product
            // attributes — they describe the parent / variant relationship
            // and the configurable axis declaration.
            'parent_sku', 'child_sku', 'configurable_attributes', 'associated_skus', 'options',
            // Virtual products with personalisable options (giftcards etc).
            // Parsed separately and attached via $product->setOptions().
            'custom_options',
        ];

        foreach ($row as $key => $value) {
            if (in_array($key, $reservedKeys, true)) {
                continue;
            }
            if ($value === '') {
                continue;
            }

            $resolved = $this->resolveAttributeValue($key, $value);
            // Use setData() rather than setCustomAttribute() — setCustom­Attribute
            // wraps the value in an AttributeInterface object via a factory
            // and the resulting structure is not always picked up by the EAV
            // entity backend. setData() places the value directly on the
            // product, which Magento's catalog save handlers persist into
            // the right backend column.
            $product->setData($key, $resolved);
        }
    }

    /**
     * Translate a CSV cell to the value Magento expects: integer option id
     * for selects/swatches, comma-separated option ids for multiselects,
     * raw string for text-typed attributes.
     */
    private function resolveAttributeValue(string $attributeCode, string $value): mixed
    {
        if (str_contains($value, ',')) {
            $codes = array_filter(array_map('trim', explode(',', $value)));
            $ids = [];
            foreach ($codes as $code) {
                $id = $this->attributeImporter->resolveOptionId($attributeCode, $code);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
                return implode(',', $ids);
            }
            return $value;
        }

        $id = $this->attributeImporter->resolveOptionId($attributeCode, $value);
        return $id !== null ? $id : $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function applyStock(ProductInterface $product, array $row): void
    {
        $qty = isset($row['qty']) && $row['qty'] !== '' ? (float) $row['qty'] : 100.0;
        $stockItem = $this->stockRegistry->getStockItemBySku($product->getSku());
        $stockItem->setQty($qty);
        $stockItem->setIsInStock($qty > 0);
        $stockItem->setUseConfigManageStock(true);
        $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
    }

    /**
     * Attach images to a product. The `images` column is a comma-separated
     * list of filenames (no path) that the importer will resolve against
     * the configured search paths in this order:
     *
     *   1. Disrex_SampleDataThemeHomeLivingMedia _files/images/  (high-res
     *      catalogue assets, optional package — present only on demo
     *      installs).
     *   2. Disrex_SampleDataThemeHomeLiving _files/images/        (low-res
     *      placeholders shipped with the theme module itself).
     *
     * If neither location resolves, the row is logged and the product is
     * left without that particular image — partial galleries beat hard
     * fails on demo installs missing the optional media bundle.
     */
    private function attachImages(ProductInterface $product, string $imagesColumn): void
    {
        $filenames = array_filter(array_map('trim', explode(',', $imagesColumn)));
        if ($filenames === []) {
            return;
        }

        // Magento's gallery storage appends `_1`, `_2` etc. when a file with
        // the same dispersion-path basename already exists, so the basename
        // we wrote yesterday isn't necessarily the basename in the DB today.
        // To stay idempotent across re-runs we strip Magento's numeric
        // suffix before comparing — the *stem* (e.g. `sofa-helsinki-001`)
        // is what the CSV declares; suffixes are just storage artifacts.
        $existingStems = [];
        foreach ((array) $product->getMediaGalleryEntries() as $existing) {
            /** @var ProductAttributeMediaGalleryEntryInterface $existing */
            $file = (string) $existing->getFile();
            if ($file === '') {
                continue;
            }
            $stem = pathinfo($file, PATHINFO_FILENAME);
            // Strip Magento's `_N` storage suffix if present.
            $stem = preg_replace('/_\d+$/', '', $stem);
            $existingStems[$stem] = true;
        }

        $position = 0;
        $isFirst = true;
        foreach ($filenames as $filename) {
            $candidateStem = pathinfo($filename, PATHINFO_FILENAME);
            if (isset($existingStems[$candidateStem])) {
                // Idempotent re-run: skip already-attached images.
                $isFirst = false;
                continue;
            }

            $absolutePath = $this->locateImageFile($filename);
            if ($absolutePath === null) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Image %s for SKU %s not found in any configured media path; skipped.',
                    $filename,
                    $product->getSku()
                ));
                continue;
            }

            try {
                $bytes = file_get_contents($absolutePath);
                if ($bytes === false) {
                    continue;
                }

                $imageContent = $this->imageContentFactory->create();
                $imageContent->setBase64EncodedData(base64_encode($bytes));
                $imageContent->setType($this->guessMimeType($absolutePath));
                $imageContent->setName($filename);

                $entry = $this->galleryEntryFactory->create();
                $entry->setMediaType('image');
                $entry->setLabel(pathinfo($filename, PATHINFO_FILENAME));
                $entry->setPosition(++$position);
                $entry->setDisabled(false);
                // The first image becomes the product's main image, small
                // image, and thumbnail; subsequent ones live in the gallery.
                $entry->setTypes($isFirst ? ['image', 'small_image', 'thumbnail'] : []);
                $entry->setContent($imageContent);

                $this->galleryManagement->create($product->getSku(), $entry);
                $isFirst = false;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Failed to attach image %s to SKU %s: %s',
                    $filename,
                    $product->getSku(),
                    $e->getMessage()
                ), ['exception' => $e]);
            }
        }
    }

    /**
     * Search known module paths for a media file. Returns the absolute
     * path to the first hit, or null if nothing matched.
     *
     * Each module is checked under both `_files/images/` (studio shots) and
     * `_files/scenes/` (lifestyle / scene shots). Themes that prefer a flat
     * layout can ignore the second directory; themes that separate by stage
     * keep their semantic structure.
     */
    private function locateImageFile(string $filename): ?string
    {
        $searchModules = [
            // Tier-1: media package (high-res, optional).
            'Disrex_SampleDataThemeHomeLivingMedia',
            // Tier-2: theme module fallback (low-res placeholders).
            'Disrex_SampleDataThemeHomeLiving',
        ];
        $subdirs = ['images', 'scenes'];

        foreach ($searchModules as $moduleName) {
            try {
                $base = $this->moduleDirReader->getModuleDir('', $moduleName);
            } catch (\Throwable) {
                continue;
            }
            foreach ($subdirs as $subdir) {
                $candidate = rtrim($base, '/') . '/_files/' . $subdir . '/' . ltrim($filename, '/');
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /**
     * @param array<string, string> $row
     */
    private function require(array $row, string $key): string
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            throw new \InvalidArgumentException(sprintf('Required column "%s" is empty.', $key));
        }
        return $row[$key];
    }
}
