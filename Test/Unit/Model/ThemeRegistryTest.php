<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Model;

use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Exception\ThemeNotRegisteredException;
use Disrex\SampleDataThemesCore\Model\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeRegistryTest extends TestCase
{
    public function testEmptyRegistryReportsItself(): void
    {
        $registry = new ThemeRegistry([]);
        self::assertTrue($registry->isEmpty());
        self::assertSame(0, $registry->count());
        self::assertSame([], $registry->all());
    }

    public function testRegistersThemesByCode(): void
    {
        $home = $this->makeTheme('home-living');
        $tech = $this->makeTheme('technology');

        $registry = new ThemeRegistry([
            'arbitrary-key' => $home,
            'another-key' => $tech,
        ]);

        self::assertFalse($registry->isEmpty());
        self::assertSame(2, $registry->count());
        self::assertTrue($registry->has('home-living'));
        self::assertTrue($registry->has('technology'));
        self::assertSame($home, $registry->get('home-living'));
        self::assertSame($tech, $registry->get('technology'));
    }

    public function testThrowsWhenInjectedValueIsNotATheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeRegistry(['bad' => new \stdClass()]);
    }

    public function testRejectsDuplicateThemeCodes(): void
    {
        $this->expectException(\LogicException::class);
        new ThemeRegistry([
            'first' => $this->makeTheme('home-living'),
            'second' => $this->makeTheme('home-living'),
        ]);
    }

    public function testGetUnknownCodeRaisesNotRegistered(): void
    {
        $registry = new ThemeRegistry([]);
        $this->expectException(ThemeNotRegisteredException::class);
        $registry->get('nope');
    }

    private function makeTheme(string $code): ThemeInterface
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getCode')->willReturn($code);
        return $theme;
    }
}
