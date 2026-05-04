<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Creates / updates categories from CSV rows. The base CSV uses url-key
 * paths (e.g. `living-room/sofas-couches`); per-locale CSVs override
 * `name`, `description`, `meta_*`, and the localized `url_key` so that each
 * storeview gets the right slug.
 *
 * Categories are looked up by their url-key path under the Default
 * Category root (id 2 in stock Magento). Themes that need a different root
 * can override the root_id constant in a subclass.
 */
class CategoryImporter
{
    public const DEFAULT_ROOT_ID = 2;

    /** @var array<string, int> path => category id (per-instance cache) */
    private array $pathCache = [];

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryFactory $modelFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Create or update a category from a base-CSV row.
     *
     * @param array<string, string> $row
     */
    public function createOrUpdateBase(array $row): CategoryInterface
    {
        $path = $this->require($row, 'path');
        $segments = array_values(array_filter(explode('/', $path)));
        if ($segments === []) {
            throw new \InvalidArgumentException('Category "path" cannot be empty.');
        }

        $parentId = self::DEFAULT_ROOT_ID;
        $accumulated = '';
        $category = null;

        foreach ($segments as $i => $urlKey) {
            $accumulated = $accumulated === '' ? $urlKey : $accumulated . '/' . $urlKey;
            if (isset($this->pathCache[$accumulated])) {
                $parentId = $this->pathCache[$accumulated];
                if ($i === count($segments) - 1) {
                    $category = $this->categoryRepository->get($parentId);
                }
                continue;
            }

            $category = $this->findChildByUrlKey($parentId, $urlKey);
            if (!$category) {
                $category = $this->categoryFactory->create();
                $category->setName(ucwords(str_replace('-', ' ', $urlKey)));
                $category->setUrlKey($urlKey);
                $category->setParentId($parentId);
                $category->setIsActive(true);
                $category->setIncludeInMenu(true);
            }

            // Apply leaf-level columns only on the final segment.
            if ($i === count($segments) - 1) {
                $this->applyBaseAttributes($category, $row);
            }

            $category = $this->categoryRepository->save($category);
            $this->pathCache[$accumulated] = (int) $category->getId();
            $parentId = (int) $category->getId();
        }

        /** @var CategoryInterface $category */
        return $category;
    }

    /**
     * Apply storeview-scoped translations.
     *
     * @param array<string, string> $row
     * @param array<int, int> $storeIds
     */
    public function applyTranslation(string $path, array $row, array $storeIds): void
    {
        if ($storeIds === []) {
            return;
        }
        $categoryId = $this->resolvePathToId($path);
        if ($categoryId === null) {
            return;
        }
        foreach ($storeIds as $storeId) {
            $category = $this->modelFactory->create();
            $category->setStoreId($storeId);
            $category->load($categoryId);
            if (!$category->getId()) {
                continue;
            }
            foreach (['name', 'description', 'meta_title', 'meta_description', 'meta_keywords', 'url_key'] as $field) {
                if (isset($row[$field]) && $row[$field] !== '') {
                    $category->setData($field, $row[$field]);
                }
            }
            $category->save();
        }
    }

    /**
     * Resolve a URL-key path under the default root to a category id.
     */
    public function resolvePathToId(string $path): ?int
    {
        if (isset($this->pathCache[$path])) {
            return $this->pathCache[$path];
        }
        $segments = array_values(array_filter(explode('/', $path)));
        $parentId = self::DEFAULT_ROOT_ID;
        $accumulated = '';
        foreach ($segments as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated . '/' . $segment;
            if (isset($this->pathCache[$accumulated])) {
                $parentId = $this->pathCache[$accumulated];
                continue;
            }
            $child = $this->findChildByUrlKey($parentId, $segment);
            if (!$child) {
                return null;
            }
            $parentId = (int) $child->getId();
            $this->pathCache[$accumulated] = $parentId;
        }
        return $parentId === self::DEFAULT_ROOT_ID ? null : $parentId;
    }

    /**
     * Resolve a list of url-key paths to category ids, including every
     * ancestor along each path. A row whose `categories` column is
     * `"living-room/coffee-tables"` should make the product visible in both
     * the leaf and its parents — admin product grids and non-anchor
     * storefront views don't aggregate descendants automatically, so we
     * link the product to each ancestor explicitly.
     *
     * @param array<int, string> $paths
     * @return array<int, int>
     */
    public function resolvePathsToIds(array $paths): array
    {
        $ids = [];
        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', $path)));
            $accumulated = '';
            foreach ($segments as $segment) {
                $accumulated = $accumulated === '' ? $segment : $accumulated . '/' . $segment;
                $id = $this->resolvePathToId($accumulated);
                if ($id !== null && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * @param array<int, string> $paths
     */
    public function deleteByPaths(array $paths): void
    {
        // Sort by depth descending so children are removed before their parents.
        usort($paths, static fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($paths as $path) {
            $id = $this->resolvePathToId($path);
            if ($id === null) {
                continue;
            }
            try {
                $this->categoryRepository->deleteByIdentifier($id);
                unset($this->pathCache[$path]);
            } catch (NoSuchEntityException) {
                // Already gone.
            }
        }
    }

    /**
     * @param array<string, string> $row
     */
    private function applyBaseAttributes(CategoryInterface $category, array $row): void
    {
        if (isset($row['is_active']) && $row['is_active'] !== '') {
            $category->setIsActive((bool) (int) $row['is_active']);
        }
        if (isset($row['include_in_menu']) && $row['include_in_menu'] !== '') {
            $category->setIncludeInMenu((bool) (int) $row['include_in_menu']);
        }
        if (isset($row['position']) && $row['position'] !== '') {
            $category->setPosition((int) $row['position']);
        }
        if (isset($row['is_anchor']) && $row['is_anchor'] !== '') {
            $category->setData('is_anchor', (int) $row['is_anchor']);
        }
    }

    /**
     * Find an existing direct child of $parentId whose url_key matches.
     *
     * Implementation note: we query catalog_category_entity_varchar
     * directly instead of going through `Category::getChildrenCategories()`.
     * The repository-loaded parent's children collection turned out to
     * miss recently-created or repository-cached siblings on subsequent
     * deploys, so the importer would treat existing categories as
     * "missing" and create duplicates — every redeploy doubled the
     * tree. A direct EAV lookup is both faster and authoritative: it
     * sees whatever is actually in the DB right now, including
     * categories created earlier in this same fixture run.
     */
    private function findChildByUrlKey(int $parentId, string $urlKey): ?CategoryInterface
    {
        $conn = $this->resourceConnection->getConnection();
        $entityTbl = $this->resourceConnection->getTableName('catalog_category_entity');
        $varcharTbl = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $urlKeyAttr = $this->eavConfig->getAttribute('catalog_category', 'url_key');

        $select = $conn->select()
            ->from(['e' => $entityTbl], ['entity_id'])
            ->joinInner(
                ['v' => $varcharTbl],
                'v.entity_id = e.entity_id'
                . ' AND v.attribute_id = ' . (int) $urlKeyAttr->getAttributeId()
                . ' AND v.store_id = 0',
                []
            )
            ->where('e.parent_id = ?', $parentId)
            ->where('v.value = ?', $urlKey)
            ->limit(1);

        $childId = $conn->fetchOne($select);
        if (!$childId) {
            return null;
        }
        try {
            return $this->categoryRepository->get((int) $childId);
        } catch (NoSuchEntityException) {
            return null;
        }
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
