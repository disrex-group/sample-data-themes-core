<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Model;

use Disrex\SampleDataThemesCore\Api\FixtureInterface;
use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Helper\Fixture\ProductImporter;
use Disrex\SampleDataThemesCore\Model\FixtureRunner;
use Disrex\SampleDataThemesCore\Model\RunResult;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Cache\TypeListInterface as CacheTypeList;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FixtureRunnerTest extends TestCase
{
    public function testRunExecutesFixturesInDeclaredOrder(): void
    {
        $calls = [];
        $a = $this->makeFixture(static function () use (&$calls): void {
            $calls[] = 'A';
        });
        $b = $this->makeFixture(static function () use (&$calls): void {
            $calls[] = 'B';
        });

        $om = $this->createMock(ObjectManagerInterface::class);
        $om->method('create')->willReturnMap([
            ['ClassA', [], $a],
            ['ClassB', [], $b],
        ]);

        $runner = new FixtureRunner(
            $om,
            new NullLogger(),
            $this->createMock(EavConfig::class),
            $this->createMock(CacheTypeList::class),
            $this->createMock(ProductImporter::class)
        );
        $theme = $this->makeTheme('t', ['ClassA', 'ClassB']);

        $result = $runner->run($theme);

        self::assertSame(['A', 'B'], $calls);
        self::assertTrue($result->isSuccessful());
        self::assertCount(2, $result->getSuccesses());
    }

    public function testRunContinuesAfterFailure(): void
    {
        $bRan = false;
        $a = $this->makeFixture(static function () {
            throw new \RuntimeException('first failed');
        });
        $b = $this->makeFixture(static function () use (&$bRan): void {
            $bRan = true;
        });

        $om = $this->createMock(ObjectManagerInterface::class);
        $om->method('create')->willReturnMap([
            ['ClassA', [], $a],
            ['ClassB', [], $b],
        ]);

        $runner = new FixtureRunner(
            $om,
            new NullLogger(),
            $this->createMock(EavConfig::class),
            $this->createMock(CacheTypeList::class),
            $this->createMock(ProductImporter::class)
        );
        $theme = $this->makeTheme('t', ['ClassA', 'ClassB']);

        $result = $runner->run($theme);

        self::assertTrue($bRan, 'Second fixture must run even when the first throws');
        self::assertFalse($result->isSuccessful());
        self::assertCount(1, $result->getFailures());
        self::assertCount(1, $result->getSuccesses());
        self::assertSame('first failed', $result->getFailures()[0]['message']);
    }

    public function testRollbackRunsInReverseOrder(): void
    {
        $calls = [];
        $a = $this->makeFixture(null, static function () use (&$calls): void {
            $calls[] = 'A';
        });
        $b = $this->makeFixture(null, static function () use (&$calls): void {
            $calls[] = 'B';
        });

        $om = $this->createMock(ObjectManagerInterface::class);
        $om->method('create')->willReturnMap([
            ['ClassA', [], $a],
            ['ClassB', [], $b],
        ]);

        $runner = new FixtureRunner(
            $om,
            new NullLogger(),
            $this->createMock(EavConfig::class),
            $this->createMock(CacheTypeList::class),
            $this->createMock(ProductImporter::class)
        );
        $theme = $this->makeTheme('t', ['ClassA', 'ClassB']);

        $runner->rollback($theme);

        self::assertSame(['B', 'A'], $calls);
    }

    /**
     * @param ?callable(): void $execute
     * @param ?callable(): void $rollback
     */
    private function makeFixture(?callable $execute, ?callable $rollback = null): FixtureInterface
    {
        $fixture = $this->createMock(FixtureInterface::class);
        $fixture->method('getLabel')->willReturn('label');
        if ($execute) {
            $fixture->method('execute')->willReturnCallback($execute);
        }
        if ($rollback) {
            $fixture->method('rollback')->willReturnCallback($rollback);
        }
        return $fixture;
    }

    /**
     * @param array<int, string> $fixtures
     */
    private function makeTheme(string $code, array $fixtures): ThemeInterface
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getCode')->willReturn($code);
        $theme->method('getFixtures')->willReturn($fixtures);
        return $theme;
    }
}
