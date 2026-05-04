<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

/**
 * Aggregate outcome of running a theme's fixtures. Immutable from the
 * caller's perspective once the runner returns it.
 */
class RunResult
{
    /** @var array<int, array{class: string, label: string}> */
    private array $successes = [];

    /** @var array<int, array{class: string, message: string}> */
    private array $failures = [];

    public function __construct(private readonly string $themeCode)
    {
    }

    public function getThemeCode(): string
    {
        return $this->themeCode;
    }

    public function addSuccess(string $fixtureClass, string $label): void
    {
        $this->successes[] = ['class' => $fixtureClass, 'label' => $label];
    }

    public function addFailure(string $fixtureClass, string $message): void
    {
        $this->failures[] = ['class' => $fixtureClass, 'message' => $message];
    }

    /** @return array<int, array{class: string, label: string}> */
    public function getSuccesses(): array
    {
        return $this->successes;
    }

    /** @return array<int, array{class: string, message: string}> */
    public function getFailures(): array
    {
        return $this->failures;
    }

    public function isSuccessful(): bool
    {
        return $this->failures === [];
    }

    public function totalCount(): int
    {
        return count($this->successes) + count($this->failures);
    }
}
