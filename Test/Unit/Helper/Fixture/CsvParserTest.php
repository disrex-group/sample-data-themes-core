<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Test\Unit\Helper\Fixture;

use Disrex\SampleDataThemesCore\Helper\Fixture\CsvParser;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase
{
    private CsvParser $parser;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->parser = new CsvParser();
        $this->tmpDir = sys_get_temp_dir() . '/disrex-sd-csv-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function testParsesAssociativeRows(): void
    {
        $path = $this->writeCsv("sku,name,price\nDRX-1,\"Sofa Helsinki\",899.00\nDRX-2,\"Vase Oslo\",19.95\n");

        $rows = iterator_to_array($this->parser->parse($path), false);

        self::assertCount(2, $rows);
        self::assertSame(['sku' => 'DRX-1', 'name' => 'Sofa Helsinki', 'price' => '899.00'], $rows[0]);
        self::assertSame(['sku' => 'DRX-2', 'name' => 'Vase Oslo', 'price' => '19.95'], $rows[1]);
    }

    public function testStripsUtf8BomFromHeader(): void
    {
        $path = $this->writeCsv("\xEF\xBB\xBFsku,name\nDRX-1,Test\n");

        $rows = iterator_to_array($this->parser->parse($path), false);

        self::assertSame(['sku' => 'DRX-1', 'name' => 'Test'], $rows[0]);
    }

    public function testTrimsValues(): void
    {
        $path = $this->writeCsv("sku,name\n  DRX-1 ,  Padded Name  \n");

        $rows = iterator_to_array($this->parser->parse($path), false);

        self::assertSame(['sku' => 'DRX-1', 'name' => 'Padded Name'], $rows[0]);
    }

    public function testHandlesShorterRowByPaddingMissingFields(): void
    {
        $path = $this->writeCsv("sku,name,price\nDRX-1,Only Two\n");

        $rows = iterator_to_array($this->parser->parse($path), false);

        self::assertSame(['sku' => 'DRX-1', 'name' => 'Only Two', 'price' => ''], $rows[0]);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        iterator_to_array($this->parser->parse('/nonexistent/file.csv'));
    }

    public function testExtractColumn(): void
    {
        $path = $this->writeCsv("sku,name\nDRX-1,A\nDRX-2,B\nDRX-3,C\n");

        self::assertSame(['DRX-1', 'DRX-2', 'DRX-3'], $this->parser->extractColumn($path, 'sku'));
    }

    public function testExtractColumnSkipsEmptyValues(): void
    {
        $path = $this->writeCsv("sku,name\nDRX-1,A\n,B\nDRX-3,C\n");

        self::assertSame(['DRX-1', 'DRX-3'], $this->parser->extractColumn($path, 'sku'));
    }

    private function writeCsv(string $content): string
    {
        $path = $this->tmpDir . '/sample.csv';
        file_put_contents($path, $content);
        return $path;
    }
}
