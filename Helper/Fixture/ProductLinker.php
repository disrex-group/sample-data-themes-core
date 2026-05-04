<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Sets related / upsell / crosssell links on a product.
 *
 * The importer is additive within a single link type — calling setLinks()
 * for `related` replaces all existing related links on that product, but
 * leaves `upsell` and `crosssell` untouched. That matches how merchants
 * usually think about link curation.
 */
class ProductLinker
{
    public const TYPE_RELATED = 'related';
    public const TYPE_UPSELL = 'upsell';
    public const TYPE_CROSSSELL = 'crosssell';

    private const SUPPORTED_TYPES = [
        self::TYPE_RELATED,
        self::TYPE_UPSELL,
        self::TYPE_CROSSSELL,
    ];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductLinkInterfaceFactory $linkFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<int, string> $linkedSkus
     */
    public function setLinks(string $sku, string $linkType, array $linkedSkus): void
    {
        if (!in_array($linkType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown link type "%s". Expected one of: %s.',
                $linkType,
                implode(', ', self::SUPPORTED_TYPES)
            ));
        }

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] ProductLinker: source SKU "%s" not found.',
                $sku
            ));
            return;
        }

        $existing = array_filter(
            $product->getProductLinks() ?? [],
            static fn (ProductLinkInterface $link): bool => $link->getLinkType() !== $linkType
        );

        $position = 0;
        foreach ($linkedSkus as $linkedSku) {
            $linkedSku = trim($linkedSku);
            if ($linkedSku === '') {
                continue;
            }
            try {
                $this->productRepository->get($linkedSku);
            } catch (NoSuchEntityException) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] ProductLinker: %s -> %s skipped, target missing.',
                    $sku,
                    $linkedSku
                ));
                continue;
            }

            /** @var ProductLinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setSku($sku);
            $link->setLinkedProductSku($linkedSku);
            $link->setLinkType($linkType);
            $link->setPosition(++$position);
            $existing[] = $link;
        }

        $product->setProductLinks(array_values($existing));
        $this->productRepository->save($product);
    }
}
