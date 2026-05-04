<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Creates / updates EAV product attributes and their swatch options. The
 * importer works against the existing EAV machinery rather than writing to
 * `eav_attribute*` tables directly, so it stays compatible with future
 * Magento changes.
 *
 * Attribute payloads use the wide CSV format from the spec:
 *
 *   attribute_code, frontend_input, is_required, is_searchable,
 *   is_filterable, is_visible_on_front, used_in_product_listing, is_global,
 *   option_codes
 *
 * The `option_codes` column carries `code|code|code` for plain selects, or
 * `code:#hex|code:#hex` for visual swatches.
 */
class AttributeImporter
{
    private const ENTITY_TYPE = Product::ENTITY;

    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly EavConfig $eavConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Create or update an attribute from a parsed base-CSV row.
     *
     * @param array<string, string> $row
     */
    public function createOrUpdate(array $row): void
    {
        $code = $this->require($row, 'attribute_code');
        $frontendInput = $row['frontend_input'] ?? 'text';

        $isNew = false;
        try {
            $attribute = $this->attributeRepository->get($code);
        } catch (NoSuchEntityException) {
            $isNew = true;
            $attribute = $this->attributeFactory->create();
            $attribute->setAttributeCode($code);
            $attribute->setEntityTypeId($this->getEntityTypeId());
        }

        $attribute->setFrontendInput($this->mapFrontendInput($frontendInput));
        $attribute->setBackendType($this->resolveBackendType($frontendInput));
        $attribute->setIsUserDefined(true);
        $attribute->setFrontendLabel(['Label']); // placeholder; per-store labels set via translations
        $attribute->setIsRequired($this->bool($row, 'is_required'));
        $attribute->setIsSearchable($this->bool($row, 'is_searchable'));
        $attribute->setIsFilterable((int) ($row['is_filterable'] ?? 0));
        $attribute->setIsVisibleOnFront($this->bool($row, 'is_visible_on_front'));
        $attribute->setUsedInProductListing($this->bool($row, 'used_in_product_listing'));
        $attribute->setIsGlobal((int) ($row['is_global'] ?? 1));
        $attribute->setIsVisible(true);
        $attribute->setIsUnique(false);

        $options = $this->parseOptionCodes($row['option_codes'] ?? '');
        if ($options !== []) {
            if ($isNew) {
                // Fresh attribute: seed the entire option set in one shot.
                $optionsToWrite = $options;
            } else {
                // Existing attribute: only write codes that aren't already
                // persisted, so we can grow the option set across deploys
                // without duplicating rows. (Re-applying the full set
                // creates duplicates because option_N keys don't carry
                // identity for already-saved options.)
                $optionsToWrite = $this->diffNewOptions($attribute->getAttributeCode(), $options);
            }

            if ($optionsToWrite !== []) {
                $attribute->setData('option', [
                    'value' => $this->buildAdminOptionPayload($optionsToWrite),
                    'order' => $this->buildOrderPayload($optionsToWrite),
                    'delete' => [],
                ]);
                if ($frontendInput === 'swatch_visual') {
                    $attribute->setData('swatch_input_type', 'visual');
                    $attribute->setData('swatchvisual', [
                        'value' => $this->buildVisualSwatchPayload($optionsToWrite),
                    ]);
                    $attribute->setData('optionvisual', [
                        'value' => $this->buildAdminOptionPayload($optionsToWrite),
                    ]);
                } elseif ($frontendInput === 'swatch_text') {
                    $attribute->setData('swatch_input_type', 'text');
                    $attribute->setData('swatchtext', [
                        'value' => $this->buildTextSwatchPayload($optionsToWrite),
                    ]);
                    $attribute->setData('optiontext', [
                        'value' => $this->buildAdminOptionPayload($optionsToWrite),
                    ]);
                }
            }
        }

        $this->attributeRepository->save($attribute);
    }

    /**
     * Apply per-store labels for an attribute and its options. Pass the
     * full set of rows from a single locale's `attributes.csv`.
     *
     * When `$isDefaultLocale` is true, also writes the admin (store_id=0)
     * frontend_label and option labels. Without that step, layered-nav
     * filter headings and admin-grid attribute selectors fall back to the
     * placeholder "Label" string set when the attribute was first created.
     * The default-locale strings are the right global default because
     * Magento uses store_id=0 as a fallback for any storeview that has
     * no explicit translation.
     *
     * @param array<int, array<string, string>> $rows
     * @param array<int, int> $storeIds
     */
    public function applyTranslations(array $rows, array $storeIds, bool $isDefaultLocale = false): void
    {
        if ($storeIds === []) {
            return;
        }

        // Group rows by attribute_code: one row per option, plus first row's
        // frontend_label is used as the attribute label.
        $byCode = [];
        foreach ($rows as $row) {
            $code = $row['attribute_code'] ?? '';
            if ($code === '') {
                continue;
            }
            $byCode[$code][] = $row;
        }

        foreach ($byCode as $code => $codeRows) {
            try {
                $attribute = $this->attributeRepository->get($code);
            } catch (NoSuchEntityException $e) {
                $this->logger->warning(sprintf(
                    '[disrex/sample-data-themes] Attribute "%s" not found while applying translations.',
                    $code
                ));
                continue;
            }

            $label = $codeRows[0]['frontend_label'] ?? '';
            if ($label !== '') {
                $storeLabels = $attribute->getStoreLabels() ?: [];
                foreach ($storeIds as $storeId) {
                    $storeLabels[$storeId] = $label;
                }
                $attribute->setStoreLabels($storeLabels);
                if ($isDefaultLocale) {
                    // Admin/global default — drives the layered-nav heading
                    // and any storeview that hasn't been translated.
                    $attribute->setDefaultFrontendLabel($label);
                    $attribute->setFrontendLabel($label);
                }
            }

            $optionLabels = [];
            foreach ($codeRows as $row) {
                $optionCode = $row['option_code'] ?? '';
                $optionLabel = $row['option_label'] ?? '';
                if ($optionCode === '' || $optionLabel === '') {
                    continue;
                }
                $optionLabels[$optionCode] = $optionLabel;
            }

            if ($optionLabels !== []) {
                // Keep store_id=0 holding the lowercase CSV code as the
                // stable identifier — that's what diffNewOptions() and
                // resolveOptionId() key on. Frontend labels are written
                // at storeview scope only, even for the default locale.
                $this->applyOptionLabels($attribute, $optionLabels, $storeIds);
            }

            $this->attributeRepository->save($attribute);
        }
    }

    /**
     * Resolve an option code to its option ID for a given attribute.
     * Reads from `eav_attribute_option_value` directly to bypass the
     * source-model option cache, which goes stale during a single import
     * run when options are created and queried in the same request.
     *
     * @return int|null Null if the attribute or option does not exist.
     */
    public function resolveOptionId(string $attributeCode, string $optionCode): ?int
    {
        try {
            $attribute = $this->eavConfig->getAttribute(self::ENTITY_TYPE, $attributeCode);
        } catch (\Throwable) {
            return null;
        }
        if (!$attribute || !$attribute->getId()) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');

        $select = $connection->select()
            ->from(['o' => $optionTable], ['option_id'])
            ->join(
                ['ov' => $optionValueTable],
                'o.option_id = ov.option_id',
                []
            )
            ->where('o.attribute_id = ?', (int) $attribute->getId())
            ->where('ov.store_id = 0')
            ->where('ov.value = ?', $optionCode)
            ->limit(1);

        $optionId = $connection->fetchOne($select);
        return $optionId !== false ? (int) $optionId : null;
    }

    /**
     * @param array<string, string> $optionCodeToLabel
     * @param array<int, int> $storeIds
     */
    private function applyOptionLabels($attribute, array $optionCodeToLabel, array $storeIds): void
    {
        // The CSV uses lowercase option codes (`beige`, `linen`). After
        // en_US translates the admin label to the human form (`Beige`),
        // the source-model label no longer matches verbatim — we use the
        // raw option_value table and option_id as the stable identifier.
        //
        // We also write the rows directly via INSERT…ON DUPLICATE KEY
        // UPDATE rather than via $attribute->setData('option', …) plus
        // attributeRepository->save(). The repository round-trip drops
        // storeview labels that happen to coincide with the en_US label,
        // breaking translations like fabric=Linen / fabric=Linnen on the
        // few options where the languages share a word. Direct writes
        // bypass that side effect.
        $connection = $this->resourceConnection->getConnection();
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $codeToOptionId = $connection->fetchPairs(
            $connection->select()
                ->from(['ov' => $optionValueTable], ['value', 'option_id'])
                ->join(['o' => $optionTable], 'o.option_id = ov.option_id', [])
                ->where('o.attribute_id = ?', (int) $attribute->getId())
                ->where('ov.store_id = 0')
        );

        foreach ($optionCodeToLabel as $code => $label) {
            $optionId = $codeToOptionId[$code] ?? null;
            if ($optionId === null) {
                continue;
            }
            foreach ($storeIds as $storeId) {
                $connection->insertOnDuplicate(
                    $optionValueTable,
                    [
                        'option_id' => (int) $optionId,
                        'store_id' => (int) $storeId,
                        'value' => $label,
                    ],
                    ['value']
                );
            }
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

    /**
     * @param array<string, string> $row
     */
    private function bool(array $row, string $key): int
    {
        $value = $row[$key] ?? '0';
        return ((int) $value === 1 || strtolower($value) === 'true') ? 1 : 0;
    }

    private function mapFrontendInput(string $input): string
    {
        return match ($input) {
            'swatch_visual', 'swatch_text' => 'select',
            default => $input,
        };
    }

    private function resolveBackendType(string $frontendInput): string
    {
        return match ($frontendInput) {
            'select', 'swatch_visual', 'swatch_text' => 'int',
            'multiselect' => 'varchar',
            'price', 'weight' => 'decimal',
            'date' => 'datetime',
            'textarea' => 'text',
            default => 'varchar',
        };
    }

    /**
     * @return array<int, array{code: string, hex?: string}>
     */
    private function parseOptionCodes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $options = [];
        foreach (explode('|', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, ':')) {
                [$code, $hex] = explode(':', $entry, 2);
                $options[] = ['code' => trim($code), 'hex' => trim($hex)];
            } else {
                $options[] = ['code' => $entry];
            }
        }
        return $options;
    }

    /**
     * @param array<int, array{code: string, hex?: string}> $options
     * @return array<string, array<int, string>>
     */
    private function buildAdminOptionPayload(array $options): array
    {
        $payload = [];
        foreach ($options as $i => $opt) {
            $key = 'option_' . $i;
            $payload[$key] = [0 => $opt['code']];
        }
        return $payload;
    }

    /**
     * @param array<int, array{code: string, hex?: string}> $options
     * @return array<string, int>
     */
    private function buildOrderPayload(array $options): array
    {
        $payload = [];
        foreach ($options as $i => $_) {
            $payload['option_' . $i] = $i * 10;
        }
        return $payload;
    }

    /**
     * @param array<int, array{code: string, hex?: string}> $options
     * @return array<string, string>
     */
    private function buildVisualSwatchPayload(array $options): array
    {
        $payload = [];
        foreach ($options as $i => $opt) {
            $payload['option_' . $i] = $opt['hex'] ?? '#cccccc';
        }
        return $payload;
    }

    /**
     * Magento's swatch plugin (Magento\Swatches\Model\Plugin\EavAttribute::
     * processTextualSwatch) iterates each option's value with reset() so the
     * payload must be nested per-store: [optionKey => [storeId => label]].
     * Store id 0 carries the admin/default label.
     *
     * @param array<int, array{code: string, hex?: string}> $options
     * @return array<string, array<int, string>>
     */
    private function buildTextSwatchPayload(array $options): array
    {
        $payload = [];
        foreach ($options as $i => $opt) {
            $payload['option_' . $i] = [0 => $opt['code']];
        }
        return $payload;
    }

    private function getEntityTypeId(): int
    {
        return (int) $this->eavConfig->getEntityType(self::ENTITY_TYPE)->getId();
    }

    /**
     * Filter `$options` down to those whose code is not yet persisted
     * against `$attributeCode`. Reads `eav_attribute_option_value` directly
     * because the EAV source-model cache is unreliable mid-import.
     *
     * @param array<int, array{code: string, hex?: string}> $options
     * @return array<int, array{code: string, hex?: string}>
     */
    private function diffNewOptions(string $attributeCode, array $options): array
    {
        $connection = $this->resourceConnection->getConnection();
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $existingCodes = $connection->fetchCol(
            $connection->select()
                ->from(['ov' => $optionValueTable], ['value'])
                ->join(['o' => $optionTable], 'o.option_id = ov.option_id', [])
                ->join(['a' => $attributeTable], 'a.attribute_id = o.attribute_id', [])
                ->where('a.attribute_code = ?', $attributeCode)
                ->where('ov.store_id = 0')
        );
        $existingCodes = array_flip(array_map('strval', $existingCodes));

        $diff = [];
        foreach ($options as $opt) {
            if (!isset($existingCodes[(string) $opt['code']])) {
                $diff[] = $opt;
            }
        }
        return $diff;
    }
}
