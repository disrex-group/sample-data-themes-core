<?php

/**
 * Minimal Magento interface stubs for unit tests.
 *
 * Keep this file narrow: only declare the bits that unit tests actually
 * need. Importer / fixture classes are tested at integration level, against
 * a real Magento install; they do not pull in this stub set.
 */

declare(strict_types=1);

namespace Magento\Framework {
    if (!interface_exists(ObjectManagerInterface::class)) {
        interface ObjectManagerInterface
        {
            public function create(string $type, array $arguments = []);

            public function get(string $type);

            public function configure(array $configuration);
        }
    }
}

namespace Magento\Framework\App\Cache {
    if (!interface_exists(TypeListInterface::class)) {
        interface TypeListInterface
        {
            public function getTypes();

            public function getInvalidated();

            public function cleanType($typeCode);

            public function invalidate($typeCode);
        }
    }
}

namespace Magento\Eav\Model {
    if (!class_exists(Config::class)) {
        class Config
        {
            public function getAttribute($entityType, $code)
            {
            }

            public function getEntityType($code)
            {
            }

            public function clear()
            {
            }
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class)) {
        interface ScopeConfigInterface
        {
            public function getValue($path, $scopeType = 'default', $scopeCode = null);

            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Store\Model {
    if (!class_exists(ScopeInterface::class)) {
        class ScopeInterface
        {
            public const SCOPE_STORE = 'store';
            public const SCOPE_STORES = 'stores';
            public const SCOPE_WEBSITE = 'website';
            public const SCOPE_WEBSITES = 'websites';
        }
    }
}

namespace Magento\Store\Api {
    if (!interface_exists(StoreRepositoryInterface::class)) {
        interface StoreRepositoryInterface
        {
            public function getList();

            public function get($storeCode);

            public function getById($id);
        }
    }
}

namespace Magento\Store\Api\Data {
    if (!interface_exists(StoreInterface::class)) {
        interface StoreInterface
        {
            public function getId();

            public function getCode();

            public function getName();
        }
    }
}
