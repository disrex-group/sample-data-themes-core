<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Helper\Fixture;

use Disrex\SampleDataThemesCore\Helper\Fixture\LocaleResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    public function testGroupsStoreviewsByConfiguredLocale(): void
    {
        $stores = [
            $this->makeStore(0, 'admin'),     // skipped
            $this->makeStore(1, 'default'),   // en_US
            $this->makeStore(2, 'nl'),        // nl_NL
            $this->makeStore(3, 'be_nl'),     // nl_NL too
            $this->makeStore(4, 'unset'),     // empty locale, skipped
        ];

        $repo = $this->createMock(StoreRepositoryInterface::class);
        $repo->method('getList')->willReturn($stores);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static function ($path, $scope, $storeId): string {
                if ($path !== 'general/locale/code') {
                    return '';
                }
                return match ((int) $storeId) {
                    1 => 'en_US',
                    2 => 'nl_NL',
                    3 => 'nl_NL',
                    default => '',
                };
            }
        );

        $resolver = new LocaleResolver($repo, $config);

        self::assertSame([1], $resolver->resolveStoreviewIds('en_US'));
        self::assertSame([2, 3], $resolver->resolveStoreviewIds('nl_NL'));
        self::assertSame([], $resolver->resolveStoreviewIds('de_DE'));

        self::assertTrue($resolver->hasStoreviewForLocale('nl_NL'));
        self::assertFalse($resolver->hasStoreviewForLocale('de_DE'));

        self::assertSame(
            ['en_US' => [1], 'nl_NL' => [2, 3]],
            $resolver->getStoreviewsByLocale()
        );
    }

    private function makeStore(int $id, string $code): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        return $store;
    }
}
