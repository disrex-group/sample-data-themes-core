<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Exception;

class MissingStoreviewException extends \RuntimeException
{
    public static function forLocale(string $locale): self
    {
        return new self(sprintf(
            'No storeview is configured for locale "%s". Pass '
            . '--auto-create-storeviews to create one, or configure a '
            . 'storeview for this locale manually.',
            $locale
        ));
    }
}
