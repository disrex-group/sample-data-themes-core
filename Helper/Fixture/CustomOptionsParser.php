<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionInterfaceFactory;

/**
 * Parses the `custom_options` CSV column into ProductCustomOptionInterface
 * instances ready to attach via $product->setOptions().
 *
 * CSV encoding (matches the bundle `options` syntax for consistency):
 *
 *     Title|input_type|required[|max_chars] || Title|input_type|required ...
 *
 * Where:
 *   * Title is the option label shown to the customer (e.g. "Message").
 *   * input_type is one of: field, area, date.
 *   * required is "1" or "0".
 *   * max_chars (optional) is an integer for field/area types.
 *
 * Example for a giftcard:
 *
 *     Recipient Name|field|1|60 || Message|area|0|255 || Delivery Date|date|0
 *
 * Three input types are sufficient for typical demo data: short text,
 * long text, and date. Adding more (file, image, dropdown, etc.) is a
 * one-line addition to the type-map below.
 */
class CustomOptionsParser
{
    private const TYPE_MAP = [
        'field' => 'field',     // single-line text
        'area'  => 'area',      // textarea
        'date'  => 'date',      // date picker
    ];

    public function __construct(
        private readonly ProductCustomOptionInterfaceFactory $optionFactory
    ) {
    }

    /**
     * @return array<int, ProductCustomOptionInterface>
     */
    public function parse(string $sku, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $blocks = array_filter(array_map('trim', explode('||', $raw)));
        $options = [];
        $sortOrder = 0;
        foreach ($blocks as $block) {
            $parts = array_map('trim', explode('|', $block));
            if (count($parts) < 3) {
                continue;
            }
            [$title, $type] = [$parts[0], $parts[1]];
            $required = (int) ($parts[2] !== '' ? $parts[2] : 0);
            $maxChars = isset($parts[3]) && $parts[3] !== '' ? (int) $parts[3] : null;

            if (!isset(self::TYPE_MAP[$type])) {
                continue;
            }

            /** @var ProductCustomOptionInterface $option */
            $option = $this->optionFactory->create();
            $option->setTitle($title);
            $option->setType(self::TYPE_MAP[$type]);
            $option->setIsRequire((bool) $required);
            $option->setSortOrder(++$sortOrder);
            $option->setProductSku($sku);
            // Custom options at this level have no monetary impact; the
            // visitor's text/date selection just lands in the line item.
            $option->setPrice(0);
            $option->setPriceType('fixed');
            if ($maxChars !== null) {
                $option->setMaxCharacters($maxChars);
            }
            $options[] = $option;
        }
        return $options;
    }
}
