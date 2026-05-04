<?php

/**
 * Test bootstrap.
 *
 * The core package depends on Magento framework interfaces that are only
 * available inside a real Magento installation. For pure unit tests we
 * declare just-enough stubs so that PHPUnit can build mocks against the
 * interface signatures without pulling in the full Magento runtime.
 *
 * Anything we add here MUST stay in sync with the upstream interface — if
 * Magento changes a signature in a way this stub does not reflect, the
 * production code will still compile fine (because it links against the
 * real interface) but our tests would silently pass against an outdated
 * shape. Treat this file as documentation of the contracts we rely on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

if (!interface_exists(\Magento\Framework\ObjectManagerInterface::class)) {
    require __DIR__ . '/stubs/magento-stubs.php';
}
