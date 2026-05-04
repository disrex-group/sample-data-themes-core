<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Helper\Fixture;

use Disrex\SampleDataThemesCore\Helper\Fixture\CsvParser;
use Disrex\SampleDataThemesCore\Helper\Fixture\TranslationLoader;
use PHPUnit\Framework\TestCase;

final class TranslationLoaderTest extends TestCase
{
    private string $i18nDir;

    protected function setUp(): void
    {
        $this->i18nDir = sys_get_temp_dir() . '/disrex-sd-i18n-' . bin2hex(random_bytes(4));
        mkdir($this->i18nDir);
        mkdir($this->i18nDir . '/en_US');
        mkdir($this->i18nDir . '/nl_NL');

        file_put_contents($this->i18nDir . '/en_US/products.csv', <<<CSV
sku,name,description
DRX-1,"Sofa Helsinki","A modular sofa"
DRX-2,"Vase Oslo",""
CSV);

        file_put_contents($this->i18nDir . '/nl_NL/products.csv', <<<CSV
sku,name,description
DRX-1,"Bank Helsinki","Een modulaire bank"
DRX-2,"",""
CSV);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->i18nDir);
    }

    public function testLoadIndexesByKeyColumn(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        $rows = $loader->load($this->i18nDir, 'en_US', 'products', 'sku');

        self::assertSame('Sofa Helsinki', $rows['DRX-1']['name']);
        self::assertSame('A modular sofa', $rows['DRX-1']['description']);
        self::assertArrayHasKey('DRX-2', $rows);
    }

    public function testGetReturnsRequestedFieldFromPrimaryLocale(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        $name = $loader->get($this->i18nDir, 'nl_NL', 'en_US', 'products', 'sku', 'DRX-1', 'name');
        self::assertSame('Bank Helsinki', $name);
    }

    public function testGetFallsBackToDefaultLocaleWhenFieldIsEmpty(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        // DRX-2.name is empty in nl_NL, populated in en_US
        $name = $loader->get($this->i18nDir, 'nl_NL', 'en_US', 'products', 'sku', 'DRX-2', 'name');
        self::assertSame('Vase Oslo', $name);
    }

    public function testGetFallsBackToDefaultLocaleWhenLocaleFileMissing(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        $name = $loader->get($this->i18nDir, 'de_DE', 'en_US', 'products', 'sku', 'DRX-1', 'name');
        self::assertSame('Sofa Helsinki', $name);
    }

    public function testGetReturnsNullWhenMissingFromBothLocales(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        $name = $loader->get($this->i18nDir, 'nl_NL', 'en_US', 'products', 'sku', 'DRX-UNKNOWN', 'name');
        self::assertNull($name);
    }

    public function testGetReturnsNullWhenFieldEmptyInBothLocales(): void
    {
        $loader = new TranslationLoader(new CsvParser());
        // DRX-2.description is empty in both files
        $description = $loader->get(
            $this->i18nDir,
            'nl_NL',
            'en_US',
            'products',
            'sku',
            'DRX-2',
            'description'
        );
        self::assertNull($description);
    }

    public function testCachesParsedRowsAcrossCalls(): void
    {
        $parser = $this->createMock(CsvParser::class);
        $parser->expects(self::once())
            ->method('parse')
            ->willReturn(new \ArrayIterator([['sku' => 'X', 'name' => 'X-name']]));

        $loader = new TranslationLoader($parser);
        $loader->load($this->i18nDir, 'en_US', 'products', 'sku');
        $loader->load($this->i18nDir, 'en_US', 'products', 'sku');
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rmTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
