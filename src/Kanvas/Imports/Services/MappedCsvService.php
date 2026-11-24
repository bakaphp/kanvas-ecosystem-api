<?php

declare(strict_types=1);

namespace Kanvas\Imports\Services;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Baka\Support\TempFile;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\CsvReaderService;

/**
 * Reads an exported CSV through an import-template mapping without knowing what the rows mean: finds the
 * header line (portals print account or company details above it), keys every row by it, and turns the
 * text a mapping resolves into numbers and dates. The bank-statement and the WebPOS issued-e-CF parsers
 * both sit on it; what a row becomes is theirs.
 */
class MappedCsvService
{
    private const int HEADER_SCAN_LINES = 25;
    private const int SAMPLE_ROWS = 3;

    /**
     * @param array<string, mixed> $mapping
     */
    public function matches(string $contents, array $mapping): bool
    {
        return $this->headerLineIndex($this->lines($contents), $this->referencedColumns($mapping)) !== null;
    }

    /**
     * Rows keyed by the header the mapping needs, in file order; null when no line holds every column it reads.
     *
     * @param array<string, mixed> $mapping
     * @return list<array<string, string>>|null
     */
    public function rowsUnderHeader(string $contents, array $mapping): ?array
    {
        $lines = $this->lines($contents);
        $headerIndex = $this->headerLineIndex($lines, $this->referencedColumns($mapping));

        return $headerIndex === null ? null : $this->records(array_slice($lines, $headerIndex));
    }

    /**
     * The likely header and a few rows under it, for a file no mapping recognises.
     *
     * @return array{header: list<string>, sample_rows: list<list<string>>}
     */
    public function peek(string $contents): array
    {
        $lines = $this->lines($contents);
        $headerIndex = $this->widestLineIndex($lines);
        $records = $this->rawRecords(array_slice($lines, $headerIndex, self::SAMPLE_ROWS + 1));

        return [
            'header' => array_map(fn ($cell): string => trim((string) $cell), $records[0] ?? []),
            'sample_rows' => array_map(
                fn (array $record): array => array_map(fn ($cell): string => trim((string) $cell), $record),
                array_slice($records, 1),
            ),
        ];
    }

    /**
     * Columns a mapping reads, i.e. what a file must contain for the mapping to apply. `_const` and `extra.`
     * values name no column.
     *
     * @param array<string, mixed> $mapping
     * @return list<string>
     */
    public function referencedColumns(array $mapping): array
    {
        $columns = [];

        foreach ($mapping as $value) {
            array_push($columns, ...$this->columnsIn($value));
        }

        return array_values(array_unique($columns));
    }

    /**
     * "RD$ 1,234.56", "(1.234,56)", "1234.56-", "US$1,000" → signed float. The thousands separator is
     * whichever of . and , is not the decimal one.
     */
    public static function number(?string $text, string $decimalSeparator = '.'): ?float
    {
        if ($text === null) {
            return null;
        }

        $negative = str_contains($text, '(') || str_ends_with(trim($text), '-') || str_starts_with(trim($text), '-');
        $thousands = $decimalSeparator === ',' ? '.' : ',';
        $clean = str_replace($thousands, '', $text);
        $clean = preg_replace('/[^0-9' . preg_quote($decimalSeparator, '/') . ']/', '', $clean);
        $clean = str_replace($decimalSeparator, '.', (string) $clean);

        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return $negative ? -(float) $clean : (float) $clean;
    }

    /**
     * An exact format when the mapping names one; otherwise a strict parse that refuses a date that does not exist.
     */
    public static function date(string $text, ?string $format): ?Carbon
    {
        if ($format !== null) {
            $parsed = DateHelper::parseExact($text, $format);

            return $parsed === null ? null : Carbon::instance($parsed);
        }

        return DateHelper::parseCarbonStrict($text)?->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function columnsIn(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' || str_starts_with($value, '_') || str_starts_with($value, 'extra.') ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $columns = [];
        foreach (['$concat', '$coalesce'] as $operator) {
            foreach ((array) ($value[$operator] ?? []) as $part) {
                array_push($columns, ...$this->columnsIn($part));
            }
        }

        if (array_key_exists('$map', $value)) {
            array_push($columns, ...$this->columnsIn($value['$map']));
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        $contents = Str::stripBom($contents);

        // Portals still export Windows-1252; a mapping written with "Descripción" must match it.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contents) ?: [],
            fn (string $line): bool => trim($line, " \t,;") !== '',
        ));
    }

    /**
     * @param list<string> $lines
     * @param list<string> $columns
     */
    private function headerLineIndex(array $lines, array $columns): ?int
    {
        if ($columns === []) {
            return null;
        }

        foreach (array_slice($lines, 0, self::HEADER_SCAN_LINES) as $index => $line) {
            $cells = array_map(
                fn ($cell): string => Str::lowerTrim((string) $cell),
                $this->rawRecords([$line])[0] ?? [],
            );

            $missing = array_filter($columns, fn (string $column): bool => ! in_array(Str::lowerTrim($column), $cells, true));

            if ($missing === []) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private function widestLineIndex(array $lines): int
    {
        $best = 0;
        $bestWidth = 0;

        foreach (array_slice($lines, 0, self::HEADER_SCAN_LINES) as $index => $line) {
            $width = count($this->rawRecords([$line])[0] ?? []);

            if ($width > $bestWidth) {
                $best = $index;
                $bestWidth = $width;
            }
        }

        return $best;
    }

    /**
     * Rows keyed by the header line, which must be first.
     *
     * @param list<string> $lines
     * @return list<array<string, string>>
     */
    private function records(array $lines): array
    {
        return TempFile::using(function (string $path) use ($lines): array {
            file_put_contents($path, implode("\n", $lines));
            $reader = CsvReaderService::fromPath($path);
            $reader->setHeaderOffset(0);

            $header = array_map(fn ($column): string => trim((string) $column), $reader->getHeader());
            $rows = [];

            foreach ($reader->getRecords($header) as $record) {
                $rows[] = array_map(fn ($cell): string => trim((string) $cell), $record);
            }

            return $rows;
        }, 'csv');
    }

    /**
     * @param list<string> $lines
     * @return list<list<string|null>>
     */
    private function rawRecords(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        return TempFile::using(function (string $path) use ($lines): array {
            file_put_contents($path, implode("\n", $lines));

            return array_values(iterator_to_array(CsvReaderService::fromPath($path)->getRecords(), false));
        }, 'csv');
    }
}
