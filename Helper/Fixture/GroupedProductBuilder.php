<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Psr\Log\LoggerInterface;

/**
 * Wires associated children onto a grouped product. Replaces all existing
 * grouped associations on the parent — link curation in one shot.
 */
class GroupedProductBuilder
{
    use InheritsChildImage;
    use ReassignsProductTypeCategory;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductLinkInterfaceFactory $linkFactory,
        private readonly LoggerInterface $logger,
        private readonly ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly Filesystem $filesystem,
        private readonly CategoryImporter $categoryImporter
    ) {
    }

    /**
     * Associations are passed as an array of ['sku' => ..., 'qty' => 1].
     *
     * @param array<int, array{sku: string, qty: float|int}> $associations
     */
    public function link(string $parentSku, array $associations): void
    {
        try {
            $parent = $this->productRepository->get($parentSku, true);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] Grouped parent "%s" not found.',
                $parentSku
            ));
            return;
        }

        $parent->setTypeId(GroupedType::TYPE_CODE);

        $existing = array_filter(
            $parent->getProductLinks() ?? [],
            static fn (ProductLinkInterface $link): bool => $link->getLinkType() !== 'associated'
        );

        $position = 0;
        foreach ($associations as $assoc) {
            $childSku = trim((string) ($assoc['sku'] ?? ''));
            if ($childSku === '') {
                continue;
            }
            try {
                $this->productRepository->get($childSku);
            } catch (NoSuchEntityException) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Grouped child "%s" not found, skipped.',
                    $childSku
                ));
                continue;
            }

            /** @var ProductLinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setSku($parentSku);
            $link->setLinkedProductSku($childSku);
            $link->setLinkType('associated');
            $link->setPosition(++$position);
            $link->getExtensionAttributes()?->setQty((float) ($assoc['qty'] ?? 1));
            $existing[] = $link;
        }

        $parent->setProductLinks(array_values($existing));
        $this->productRepository->save($parent);

        $this->reassignToTypeCategory(
            $parent,
            'product-types/grouped',
            $this->categoryImporter,
            $this->productRepository,
            $this->logger
        );

        $childSkus = array_map(
            static fn (array $a): string => trim((string) $a['sku']),
            $associations
        );
        $this->inheritImageFromChild(
            $parent,
            array_filter($childSkus),
            $this->productRepository,
            $this->galleryManagement,
            $this->galleryEntryFactory,
            $this->imageContentFactory,
            $this->filesystem,
            $this->logger
        );
    }
}
