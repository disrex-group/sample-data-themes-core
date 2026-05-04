<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeManagementInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Set as AttributeSet;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Creates / updates EAV attribute sets and assigns attributes to them.
 *
 * Attribute sets are derived from a "skeleton" — usually the Default product
 * attribute set — so they inherit the system attributes Magento needs.
 * Theme-defined attributes are then attached to a single group inside the
 * new set.
 */
class AttributeSetImporter
{
    /** @var array<string, int> name => attribute_set_id */
    private array $cache = [];

    public function __construct(
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly AttributeSetFactory $attributeSetFactory,
        private readonly AttributeManagementInterface $attributeManagement,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly EavConfig $eavConfig,
        private readonly EavSetup $eavSetup,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Product $productModel
    ) {
    }

    /**
     * Get an attribute-set id by name, creating it from the default skeleton
     * if it doesn't exist.
     */
    public function getOrCreate(string $name): int
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_name', $name)
            ->addFilter('entity_type_id', $entityTypeId)
            ->create();
        foreach ($this->attributeSetRepository->getList($criteria)->getItems() as $existing) {
            /** @var AttributeSetInterface $existing */
            $this->cache[$name] = (int) $existing->getAttributeSetId();
            return $this->cache[$name];
        }

        $skeletonId = (int) $this->productModel->getDefaultAttributeSetId();

        /** @var AttributeSet $set */
        $set = $this->attributeSetFactory->create();
        $set->setData([
            'attribute_set_name' => $name,
            'entity_type_id' => $entityTypeId,
        ]);
        $set->validate();
        $set->save();
        $set->initFromSkeleton($skeletonId);
        $set->save();

        $this->cache[$name] = (int) $set->getId();
        return $this->cache[$name];
    }

    /**
     * Assign attributes (by code) to the named set, in the named group.
     *
     * Attributes already assigned are silently skipped, so the call is
     * idempotent.
     *
     * @param array<int, string> $attributeCodes
     */
    public function assignAttributes(string $setName, string $groupName, array $attributeCodes): void
    {
        if ($attributeCodes === []) {
            return;
        }

        $setId = $this->getOrCreate($setName);
        $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();

        $groupId = $this->resolveGroupId($entityTypeId, $setId, $groupName);

        foreach ($attributeCodes as $sortOrder => $code) {
            try {
                /** @var ProductAttributeInterface $attribute */
                $attribute = $this->attributeRepository->get($code);
            } catch (\Throwable) {
                continue;
            }

            if ($this->isAlreadyAssigned($attribute, $setId)) {
                continue;
            }

            $this->attributeManagement->assign(
                Product::ENTITY,
                $setId,
                $groupId,
                $code,
                (int) $sortOrder * 10
            );
        }
    }

    private function resolveGroupId(int $entityTypeId, int $setId, string $groupName): int
    {
        $existingGroupId = $this->eavSetup->getAttributeGroupId($entityTypeId, $setId, $groupName);
        if ($existingGroupId) {
            return (int) $existingGroupId;
        }
        $this->eavSetup->addAttributeGroup($entityTypeId, $setId, $groupName);
        return (int) $this->eavSetup->getAttributeGroupId($entityTypeId, $setId, $groupName);
    }

    private function isAlreadyAssigned(ProductAttributeInterface $attribute, int $setId): bool
    {
        $sets = $attribute->getData('attribute_set_info');
        if (is_array($sets) && isset($sets[$setId])) {
            return true;
        }
        // Fallback: walk the existing assignments.
        foreach ($this->attributeManagement->getAttributes(Product::ENTITY, $setId) as $assigned) {
            if ($assigned->getAttributeCode() === $attribute->getAttributeCode()) {
                return true;
            }
        }
        return false;
    }
}
