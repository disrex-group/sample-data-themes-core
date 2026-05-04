<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Wires up a configurable product: declares its configurable attributes,
 * binds the variant child SKUs, and saves. Idempotent — re-running with
 * the same set of children leaves the product unchanged.
 */
class ConfigurableProductBuilder
{
    use InheritsChildImage;
    use ReassignsProductTypeCategory;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ConfigurableOptionsFactory $optionsFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly Filesystem $filesystem,
        private readonly CategoryImporter $categoryImporter
    ) {
    }

    /**
     * @param array<int, string> $childSkus
     * @param array<int, string> $configurableAttributeCodes
     */
    public function link(string $parentSku, array $childSkus, array $configurableAttributeCodes): void
    {
        try {
            $parent = $this->productRepository->get($parentSku, true);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] Configurable parent "%s" not found.',
                $parentSku
            ));
            return;
        }

        $children = $this->loadChildren($childSkus);
        if ($children === []) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] Configurable "%s" has no resolvable children.',
                $parentSku
            ));
            return;
        }

        $parent->setTypeId(ConfigurableType::TYPE_CODE);

        $attributeData = $this->buildAttributeData($configurableAttributeCodes, $children);
        if ($attributeData === []) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] No valid configurable attributes for "%s".',
                $parentSku
            ));
            return;
        }

        $configurableOptions = $this->optionsFactory->create($attributeData);
        $extension = $parent->getExtensionAttributes();
        $extension->setConfigurableProductOptions($configurableOptions);

        $childIds = array_map(
            static fn ($child) => (int) $child->getId(),
            $children
        );
        $extension->setConfigurableProductLinks($childIds);
        $parent->setExtensionAttributes($extension);

        $this->productRepository->save($parent);

        // Re-bind the parent's product-type cross-cut category. When the
        // parent was first created in step 2 of ConfigurableProductFixture
        // it was a type=simple placeholder, so ProductImporter linked it
        // into product-types/simple. Now that we've promoted the type to
        // configurable we want it under product-types/configurable. The
        // simples link gets removed; everything else (its real leaf
        // category, etc.) stays.
        $this->reassignToTypeCategory(
            $parent,
            'product-types/configurable',
            $this->categoryImporter,
            $this->productRepository,
            $this->logger
        );

        // Inherit after the parent save so the parent has a stable id and
        // the gallery API can attach to it.
        $childSkus = array_map(
            static fn ($c): string => (string) $c->getSku(),
            $children
        );
        $this->inheritImageFromChild(
            $parent,
            $childSkus,
            $this->productRepository,
            $this->galleryManagement,
            $this->galleryEntryFactory,
            $this->imageContentFactory,
            $this->filesystem,
            $this->logger
        );
    }

    /**
     * @param array<int, string> $codes
     * @param array<int, \Magento\Catalog\Api\Data\ProductInterface> $children
     *
     * @return array<int, array{
     *     attribute_id: int, code: string, label: string,
     *     position: int, values: array<int, array{value_index: int}>
     * }>
     */
    private function buildAttributeData(array $codes, array $children): array
    {
        $data = [];
        foreach ($codes as $position => $code) {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }

            // Collect distinct option IDs that the children actually use for
            // this configurable axis. Magento needs these in the
            // configurable option payload — without them the save fails
            // with "Option values are not specified".
            $valueIds = [];
            foreach ($children as $child) {
                $optionId = $child->getData($code);
                if ($optionId !== null && $optionId !== '' && !in_array((int) $optionId, $valueIds, true)) {
                    $valueIds[] = (int) $optionId;
                }
            }

            $values = [];
            foreach ($valueIds as $valueId) {
                $values[] = ['value_index' => $valueId];
            }

            $data[] = [
                'attribute_id' => (int) $attribute->getId(),
                'code' => $code,
                'label' => $attribute->getStoreLabel() ?: ucfirst($code),
                'position' => (int) $position,
                'values' => $values,
            ];
        }
        return $data;
    }

    /**
     * @param array<int, string> $skus
     * @return array<int, \Magento\Catalog\Api\Data\ProductInterface>
     */
    private function loadChildren(array $skus): array
    {
        $children = [];
        foreach ($skus as $sku) {
            try {
                $children[] = $this->productRepository->get(trim($sku));
            } catch (NoSuchEntityException) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Configurable child "%s" not found, skipped.',
                    $sku
                ));
            }
        }
        return $children;
    }
}
