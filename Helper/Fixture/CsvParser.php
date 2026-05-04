<?php

declare(strict_types=1);

namespace Disrex\SampleDataThemesCore\Helper\Fixture;

/**
 * Streams rows from a UTF-8 CSV file as associative arrays keyed by the
 * header row. Trims values, treats the empty string the same as a missing
 * field, and decodes a UTF-8 BOM if present.
 *
 * The format is intentionally narrow: comma-separated, double-quoted, with
 * one header row at the top. Anything fancier (TSV, JSON-Lines, ...) is out
 * of scope — themes that need it can convert at build time.
 */
class CsvParser
{
    /**
     * @param string $path Absolute path to the CSV file.
     *
     * @return iterable<int, array<string, string>>
     *
     * @throws \RuntimeException If the file cannot be opened or has no
     *                           usable header row.
     */
    public function parse(string $path): iterable
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('CSV file not readable: %s', $path));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open CSV file: %s', $path));
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header === false) {
                throw new \RuntimeException(sprintf('CSV file has no header row: %s', $path));
            }
            $header = $this->stripBom($header);
            $header = array_map(static fn (?string $v): string => trim((string) $v), $header);

            $rowNumber = 1;
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;

                // Skip blank lines that fgetcsv returns as [null].
                if ($row === [null]) {
                    continue;
                }

                if (count($row) !== count($header)) {
                    // Pad / trim so the assoc array still works; callers can
                    // detect the off-shape via the missing/extra keys.
                    $row = array_pad(array_slice($row, 0, count($header)), count($header), '');
                }

                /** @var array<string, string> $assoc */
                $assoc = array_combine(
                    $header,
                    array_map(static fn (?string $v): string => $v === null ? '' : trim($v), $row)
                );

                yield $assoc;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Convenience: collect a single column (e.g. all SKUs) from a CSV. Used
     * by rollback paths that need to know which entities a fixture owns.
     *
     * @return array<int, string>
     */
    public function extractColumn(string $path, string $column): array
    {
        $values = [];
        foreach ($this->parse($path) as $row) {
            if (isset($row[$column]) && $row[$column] !== '') {
                $values[] = $row[$column];
            }
        }
        return $values;
    }

    /**
     * Strip a UTF-8 BOM from the first header cell, if present.
     *
     * @param array<int, string|null> $header
     * @return array<int, string|null>
     */
    private function stripBom(array $header): array
    {
        if (isset($header[0]) && is_string($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr($header[0], 3);
        }
        return $header;
    }
}
