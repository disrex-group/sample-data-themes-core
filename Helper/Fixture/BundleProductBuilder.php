<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Bundle\Api\Data\OptionInterfaceFactory;
use Magento\Bundle\Api\ProductLinkManagementInterface;
use Magento\Bundle\Api\ProductOptionManagementInterface;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Composes a bundle product: each option is a logical "choose one" group
 * (or radio / checkbox / multiselect), and each option holds N selectable
 * SKUs.
 *
 * Existing options on the parent are removed before re-applying — bundle
 * imports are re-runnable.
 */
class BundleProductBuilder
{
    use InheritsChildImage;
    use ReassignsProductTypeCategory;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductOptionManagementInterface $optionManagement,
        private readonly ProductOptionRepositoryInterface $optionRepository,
        private readonly ProductLinkManagementInterface $linkManagement,
        private readonly OptionInterfaceFactory $optionFactory,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly LoggerInterface $logger,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly Filesystem $filesystem,
        private readonly CategoryImporter $categoryImporter
    ) {
    }

    /**
     * @param array<int, array{
     *     title: string,
     *     type?: string,
     *     required?: bool,
     *     position?: int,
     *     selections: array<int, array{
     *         sku: string, qty?: float|int, price_type?: int,
     *         price?: float, is_default?: bool
     *     }>
     * }> $options
     */
    public function compose(string $parentSku, array $options): void
    {
        try {
            $parent = $this->productRepository->get($parentSku, true);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] Bundle parent "%s" not found.',
                $parentSku
            ));
            return;
        }

        $parent->setTypeId(BundleType::TYPE_CODE);
        $parent->setData('price_type', 0);   // dynamic price (sum of selections)
        $parent->setData('price_view', 0);
        $parent->setData('shipment_type', 0);
        $this->productRepository->save($parent);

        $this->reassignToTypeCategory(
            $parent,
            'product-types/bundle',
            $this->categoryImporter,
            $this->productRepository,
            $this->logger
        );

        // Remove existing options to keep the import idempotent.
        // Bundle option listing/deletion live on the repository interface,
        // not on OptionManagement (which only declares save()).
        foreach ($this->optionRepository->getList($parentSku) as $existing) {
            $this->optionRepository->deleteById($parentSku, (int) $existing->getOptionId());
        }

        foreach ($options as $position => $optionData) {
            $option = $this->optionFactory->create();
            $option->setTitle((string) $optionData['title']);
            $option->setType((string) ($optionData['type'] ?? 'select'));
            $option->setRequired((bool) ($optionData['required'] ?? true));
            $option->setPosition((int) ($optionData['position'] ?? $position));
            $option->setSku($parentSku);

            $optionId = $this->optionManagement->save($option);

            foreach ($optionData['selections'] as $selectionPosition => $sel) {
                $selectionSku = trim((string) ($sel['sku'] ?? ''));
                if ($selectionSku === '') {
                    continue;
                }
                try {
                    $this->productRepository->get($selectionSku);
                } catch (NoSuchEntityException) {
                    $this->logger->warning(sprintf(
                        '[disrex/sample-data-themes] Bundle selection "%s" not found, skipped.',
                        $selectionSku
                    ));
                    continue;
                }

                $link = $this->linkFactory->create();
                $link->setSku($selectionSku);
                $link->setOptionId((int) $optionId);
                $link->setQty((float) ($sel['qty'] ?? 1));
                $link->setPosition((int) $selectionPosition);
                $link->setIsDefault((bool) ($sel['is_default'] ?? false));
                $link->setPriceType((int) ($sel['price_type'] ?? 0));
                if (isset($sel['price'])) {
                    $link->setPrice((float) $sel['price']);
                }
                $link->setCanChangeQuantity(0);

                $this->linkManagement->addChildByProductSku($parentSku, (int) $optionId, $link);
            }
        }

        // Once links are in place, give the bundle parent its own hero
        // image inherited from the first selectable child.
        $allSelectionSkus = [];
        foreach ($options as $opt) {
            foreach ($opt['selections'] ?? [] as $sel) {
                if (!empty($sel['sku'])) {
                    $allSelectionSkus[] = (string) $sel['sku'];
                }
            }
        }
        $this->inheritImageFromChild(
            $parent,
            $allSelectionSkus,
            $this->productRepository,
            $this->galleryManagement,
            $this->galleryEntryFactory,
            $this->imageContentFactory,
            $this->filesystem,
            $this->logger
        );
    }
}
