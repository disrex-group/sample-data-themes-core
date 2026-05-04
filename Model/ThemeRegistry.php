<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Model;

use Disrex\SampleDataThemesCore\Api\ThemeInterface;
use Disrex\SampleDataThemesCore\Exception\ThemeNotRegisteredException;

/**
 * Holds the set of themes injected via di.xml.
 *
 * The registry is the single discovery point for the framework. Theme
 * packages add entries to its `themes` argument; nothing else needs to know
 * which packages are installed.
 */
class ThemeRegistry
{
    /** @var array<string, ThemeInterface> keyed by theme code */
    private array $themes = [];

    /**
     * @param array<string, ThemeInterface> $themes Injected via di.xml. The
     *                                              array key is informational;
     *                                              the registry re-keys by
     *                                              {@see ThemeInterface::getCode()}.
     */
    public function __construct(array $themes = [])
    {
        foreach ($themes as $injectedKey => $theme) {
            if (!$theme instanceof ThemeInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Theme registered as "%s" must implement %s, got %s.',
                    is_string($injectedKey) ? $injectedKey : (string) $injectedKey,
                    ThemeInterface::class,
                    get_debug_type($theme)
                ));
            }

            $code = $theme->getCode();
            if (isset($this->themes[$code])) {
                throw new \LogicException(sprintf(
                    'Duplicate theme code "%s" — registered by both "%s" and "%s".',
                    $code,
                    get_class($this->themes[$code]),
                    get_class($theme)
                ));
            }
            $this->themes[$code] = $theme;
        }
    }

    /**
     * @return array<string, ThemeInterface>
     */
    public function all(): array
    {
        return $this->themes;
    }

    /**
     * @throws ThemeNotRegisteredException
     */
    public function get(string $code): ThemeInterface
    {
        if (!isset($this->themes[$code])) {
            throw ThemeNotRegisteredException::forCode($code);
        }
        return $this->themes[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->themes[$code]);
    }

    public function isEmpty(): bool
    {
        return $this->themes === [];
    }

    public function count(): int
    {
        return count($this->themes);
    }
}
