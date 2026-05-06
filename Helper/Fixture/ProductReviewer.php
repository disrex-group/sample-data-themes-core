<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Psr\Log\LoggerInterface;

/**
 * Creates approved customer reviews on products for sample-data themes.
 *
 * Behaviour:
 *   - Reviews are saved through Magento's Review model so its
 *     `aggregate()` step recomputes review_entity_summary — that's what
 *     the storefront reads to render the average-stars bar on PDPs and
 *     category cards.
 *   - Rating votes are applied per detected rating dimension (Quality,
 *     Price, Value on a stock install). The same star-count is applied
 *     to every dimension for sample data; that mirrors how casual
 *     customers fill the form anyway.
 *   - All reviews land approved (status=1) and visible on every store
 *     id passed to addReview().
 *
 * Idempotency: each call to addReview() inserts a new review row. The
 * fixture using this helper is responsible for skipping products that
 * already have reviews — see ProductReviewsFixture for the existence
 * check.
 */
class ProductReviewer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ReviewFactory $reviewFactory,
        private readonly RatingFactory $ratingFactory,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Append a single review to a product.
     *
     * @param string $sku       The product to review.
     * @param int $stars        1-5; applied to every rating dimension.
     * @param string $nickname  Author display name.
     * @param string $title     Review headline.
     * @param string $body      Review body / detail text.
     * @param array<int> $storeIds  Store ids the review should be visible on.
     */
    public function addReview(
        string $sku,
        int $stars,
        string $nickname,
        string $title,
        string $body,
        array $storeIds
    ): void {
        if ($stars < 1 || $stars > 5) {
            throw new \InvalidArgumentException("stars must be 1-5, got $stars");
        }
        if ($storeIds === []) {
            return;
        }

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf(
                '[disrex/sample-data-themes] ProductReviewer: SKU "%s" not found.',
                $sku
            ));
            return;
        }

        /** @var Review $review */
        $review = $this->reviewFactory->create();
        $review->setEntityId($review->getEntityIdByCode(Review::ENTITY_PRODUCT_CODE));
        $review->setEntityPkValue((int) $product->getId());
        $review->setStatusId(Review::STATUS_APPROVED);
        $review->setTitle($title);
        $review->setDetail($body);
        $review->setNickname($nickname);
        $review->setStoreId($storeIds[0]);
        $review->setStores($storeIds);
        $review->save();

        // Apply the same star count to every rating dimension on the
        // entity. On a fresh Magento install that's Quality, Price and
        // Value (3 ratings); custom-attribute installs may have more.
        $ratings = $this->loadRatingsForEntity('product');
        foreach ($ratings as $rating) {
            // DB column values come back as strings under most PDO
            // drivers; cast to int here so findOptionIdForStars and
            // the rating model both get the type they expect.
            $ratingId = (int) $rating['rating_id'];
            $optionId = $this->findOptionIdForStars($ratingId, $stars);
            if ($optionId === null) {
                continue;
            }
            $ratingModel = $this->ratingFactory->create();
            $ratingModel->setRatingId($ratingId);
            $ratingModel->setReviewId((int) $review->getId());
            $ratingModel->addOptionVote($optionId, (int) $product->getId());
        }

        // Aggregate refreshes review_entity_summary — the storefront's
        // source of truth for average stars and review count on the PDP.
        $review->aggregate();
    }

    /**
     * Quick existence check so the calling fixture can skip products
     * that already have reviews on a re-run.
     */
    public function hasReviews(string $sku): bool
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            return false;
        }
        $conn = $this->resource->getConnection();
        $count = $conn->fetchOne(
            $conn->select()
                ->from(['rdv' => $this->resource->getTableName('review_entity_summary')], ['reviews_count'])
                ->where('rdv.entity_pk_value = ?', (int) $product->getId())
                ->limit(1)
        );
        return $count !== false && (int) $count > 0;
    }

    /**
     * @return array<int, array{rating_id: int}>
     */
    private function loadRatingsForEntity(string $entityCode): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $conn->select()
                ->from(
                    ['r' => $this->resource->getTableName('rating')],
                    ['rating_id']
                )
                ->joinInner(
                    ['re' => $this->resource->getTableName('rating_entity')],
                    're.entity_id = r.entity_id',
                    []
                )
                ->where('re.entity_code = ?', $entityCode)
        );
    }

    /**
     * Ensure every product-entity rating is visible on the given store
     * ids. Magento's review aggregator only counts votes on ratings that
     * are mapped via `rating_store` for the target storeview — without
     * this step, only the stock "Rating" dimension (which ships
     * pre-mapped to admin + the default storeview) ends up reflected
     * on the storefront, while custom ratings like Quality/Value/Price
     * insert votes that never aggregate.
     *
     * Idempotent: uses INSERT IGNORE, so existing mappings are
     * untouched.
     *
     * @param array<int, int> $storeIds
     */
    public function ensureRatingsAssignedToStores(array $storeIds): void
    {
        if ($storeIds === []) {
            return;
        }
        $conn = $this->resource->getConnection();
        $ratings = $this->loadRatingsForEntity('product');
        if ($ratings === []) {
            return;
        }
        // Always include store_id=0 (admin / default scope) — the
        // Magento aggregator joins through it for fall-through.
        $allStores = array_unique(array_merge([0], array_map('intval', $storeIds)));

        $rows = [];
        foreach ($ratings as $rating) {
            foreach ($allStores as $storeId) {
                $rows[] = [
                    'rating_id' => (int) $rating['rating_id'],
                    'store_id' => $storeId,
                ];
            }
        }
        $conn->insertOnDuplicate(
            $this->resource->getTableName('rating_store'),
            $rows,
            ['store_id']
        );

        // The `rating` table has an `is_active` flag that the storefront
        // also checks. Ensure every product-entity rating is active.
        $ratingIds = array_map(static fn ($r) => (int) $r['rating_id'], $ratings);
        $conn->update(
            $this->resource->getTableName('rating'),
            ['is_active' => 1],
            ['rating_id IN (?)' => $ratingIds]
        );
    }

    private function findOptionIdForStars(int $ratingId, int $stars): ?int
    {
        $conn = $this->resource->getConnection();
        $optionId = $conn->fetchOne(
            $conn->select()
                ->from(
                    ['ro' => $this->resource->getTableName('rating_option')],
                    ['option_id']
                )
                ->where('ro.rating_id = ?', $ratingId)
                ->where('ro.value = ?', $stars)
                ->limit(1)
        );
        return $optionId === false ? null : (int) $optionId;
    }
}
