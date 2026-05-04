<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

/**
 * Discriminated union over the three outcomes of a primary-locale
 * promotion: success (with row counts), no-op skip (already promoted),
 * or fail (configuration mismatch). Allows the deploy command to
 * present a meaningful summary line without cracking open exceptions.
 */
final class PromoteResult
{
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        /** @var array<string, int> */
        public readonly array $stats
    ) {
    }

    public static function ok(array $stats): self
    {
        return new self('ok', '', $stats);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', $message, []);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message, []);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
