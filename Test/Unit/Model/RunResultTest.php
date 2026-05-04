<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Model;

use Disrex\SampleDataThemesCore\Model\RunResult;
use PHPUnit\Framework\TestCase;

final class RunResultTest extends TestCase
{
    public function testFreshResultIsSuccessful(): void
    {
        $result = new RunResult('home-living');
        self::assertSame('home-living', $result->getThemeCode());
        self::assertTrue($result->isSuccessful());
        self::assertSame(0, $result->totalCount());
    }

    public function testTracksSuccessesAndFailures(): void
    {
        $result = new RunResult('home-living');
        $result->addSuccess('A', 'first');
        $result->addSuccess('B', 'second');
        $result->addFailure('C', 'boom');

        self::assertFalse($result->isSuccessful());
        self::assertCount(2, $result->getSuccesses());
        self::assertCount(1, $result->getFailures());
        self::assertSame(3, $result->totalCount());
        self::assertSame(['class' => 'C', 'message' => 'boom'], $result->getFailures()[0]);
    }
}
