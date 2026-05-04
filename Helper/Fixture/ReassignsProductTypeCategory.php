<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared logic for moving a product from product-types/simple into the
 * category that matches its real type (configurable / bundle / grouped)
 * after it has been promoted in step 3 of one of the build fixtures.
 *
 * The simples link is added by ProductImporter when the parent first
 * lands in step 2 (where it has type_id=simple). After the parent's
 * type flips to its real value, this trait swaps the cross-cut.
 */
trait ReassignsProductTypeCategory
{
    private function reassignToTypeCategory(
        ProductInterface $parent,
        string $targetTypePath,
        CategoryImporter $categoryImporter,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ): void {
        $targetCatId = $categoryImporter->resolvePathToId($targetTypePath);
        $simpleCatId = $categoryImporter->resolvePathToId('product-types/simple');
        if ($targetCatId === null) {
            return;
        }

        $current = array_map('intval', (array) $parent->getCategoryIds());
        if ($simpleCatId !== null) {
            $current = array_values(array_filter($current, static fn ($id) => $id !== $simpleCatId));
        }
        if (!in_array($targetCatId, $current, true)) {
            $current[] = $targetCatId;
        }

        try {
            $fresh = $productRepository->get($parent->getSku(), true);
            $fresh->setCategoryIds($current);
            $productRepository->save($fresh);
        } catch (\Throwable $e) {
            $logger->warning(sprintf(
                '[disrex/sample-data-themes] Failed to reassign product-type category for "%s": %s',
                $parent->getSku(),
                $e->getMessage()
            ));
        }
    }
}
