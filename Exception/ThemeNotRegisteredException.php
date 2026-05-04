<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Exception;

class ThemeNotRegisteredException extends \OutOfBoundsException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Theme "%s" is not registered.', $code));
    }
}
