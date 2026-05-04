<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;

/**
 * Shared helper used by parents (configurable / bundle / grouped) that
 * don't have their own image but do reference children that do. Copies
 * the first child's main image to the parent through the gallery API.
 *
 * Why through the gallery API: setting `$parent->setData('image', '/x/y.jpg')`
 * directly is silently rejected by Magento's image attribute backend
 * model. Only an upload via `MediaGalleryManagement::create()` writes
 * both the gallery row and the EAV pointers (image / small_image /
 * thumbnail) atomically.
 */
trait InheritsChildImage
{
    private function inheritImageFromChild(
        ProductInterface $parent,
        array $childSkus,
        ProductRepositoryInterface $productRepository,
        ProductAttributeMediaGalleryManagementInterface $galleryManagement,
        ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        ImageContentInterfaceFactory $imageContentFactory,
        Filesystem $filesystem,
        \Psr\Log\LoggerInterface $logger
    ): void {
        // If the parent already has gallery rows, leave it alone — re-runs
        // shouldn't accumulate copies of the same image.
        $existing = (array) $parent->getMediaGalleryEntries();
        if ($existing !== []) {
            return;
        }

        $mediaRoot = $filesystem
            ->getDirectoryRead(DirectoryList::MEDIA)
            ->getAbsolutePath('catalog/product');

        foreach ($childSkus as $childSku) {
            try {
                // forceReload so we pick up the gallery the child just got
                // a few fixture steps ago.
                $child = $productRepository->get((string) $childSku, false, null, true);
            } catch (NoSuchEntityException) {
                continue;
            }
            $entries = (array) $child->getMediaGalleryEntries();
            if ($entries === []) {
                continue;
            }
            $relative = (string) $entries[0]->getFile();
            if ($relative === '') {
                continue;
            }

            $absolute = rtrim($mediaRoot, '/') . '/' . ltrim($relative, '/');
            if (!is_readable($absolute)) {
                $logger->warning(sprintf(
                    '[disrex/sample-data-themes] Parent %s: child image %s not readable.',
                    $parent->getSku(),
                    $absolute
                ));
                continue;
            }

            try {
                $bytes = file_get_contents($absolute);
                if ($bytes === false) {
                    continue;
                }
                $image = $imageContentFactory->create();
                $image->setBase64EncodedData(base64_encode($bytes));
                $image->setType($this->guessMimeType($absolute));
                $image->setName(basename($absolute));

                $entry = $galleryEntryFactory->create();
                $entry->setMediaType('image');
                $entry->setLabel(pathinfo($absolute, PATHINFO_FILENAME));
                $entry->setPosition(1);
                $entry->setDisabled(false);
                $entry->setTypes(['image', 'small_image', 'thumbnail']);
                $entry->setContent($image);

                $galleryManagement->create((string) $parent->getSku(), $entry);
            } catch (\Throwable $e) {
                $logger->warning(sprintf(
                    '[disrex/sample-data-themes] Parent %s: failed to inherit child image %s: %s',
                    $parent->getSku(),
                    $absolute,
                    $e->getMessage()
                ));
            }
            return;
        }
    }

    private function guessMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
