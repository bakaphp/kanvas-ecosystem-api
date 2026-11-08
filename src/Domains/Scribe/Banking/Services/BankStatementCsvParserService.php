<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Baka\Support\TempFile;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Services\CsvReaderService;
use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Exceptions\BankStatementRejectedException;

/**
 * Turns a bank's CSV export into statement lines through a FilesystemMapper. Every per-bank difference
 * — column names, debit/credit vs signed amount vs a type column, date format, decimal separator — is
 * in the mapping JSON (see resources/import-templates/bank-statement-*.json). This class only knows the
 * target keys:
 *
 *   date, amount | debit + credit, direction, running_balance, description, reference, counterparty,
 *   date_format (a `_const`), decimal_separator (a `_const`)
 *
 * Bank exports put account details above the column header, so the header is the first line holding
 * every column the mapping reads, not line one.
 */
class BankStatementCsvParserService
{
    private const int HEADER_SCAN_LINES = 25;
    private const int SAMPLE_ROWS = 3;

    /** Mapping keys that hold settings rather than columns. */
    private const array SETTING_KEYS = ['date_format', 'decimal_separator'];

    /**
     * @param array<string, mixed> $mapping
     */
    public function matches(string $contents, array $mapping): bool
    {
        return $this->headerLineIndex($this->lines($contents), $this->referencedColumns($mapping)) !== null;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function parse(string $contents, array $mapping): ParsedBankStatement
    {
        $lines = $this->lines($contents);
        $headerIndex = $this->headerLineIndex($lines, $this->referencedColumns($mapping));

        if ($headerIndex === null) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::NO_ROWS,
                'The mapping\'s columns are not in this file.',
            );
        }

        $statementLines = [];

        foreach ($this->records(array_slice($lines, $headerIndex)) as $number => $row) {
            $line = $this->toLine($row, $mapping, $number + 1);

            if ($line !== null) {
                $statementLines[] = $line;
            }
        }

        return new ParsedBankStatement(lines: $statementLines);
    }

    /**
     * The likely header and a few rows under it, for a file no mapping recognises — what the agent needs
     * to propose a mapping.
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
     * Columns a mapping reads, i.e. what a file must contain for the mapping to apply.
     *
     * @param array<string, mixed> $mapping
     * @return list<string>
     */
    public function referencedColumns(array $mapping): array
    {
        $columns = [];

        foreach ($mapping as $key => $value) {
            if (in_array($key, self::SETTING_KEYS, true)) {
                continue;
            }

            array_push($columns, ...$this->columnsIn($value));
        }

        return array_values(array_unique($columns));
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

        // Bank portals still export Windows-1252; a mapping written with "Descripción" must match it.
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

    /**
     * @param array<string, string> $row
     * @param array<string, mixed> $mapping
     */
    private function toLine(array $row, array $mapping, int $rowNumber): ?BankStatementLine
    {
        $decimalSeparator = FilesystemRowMapper::resolveText($mapping['decimal_separator'] ?? '_.', $row) ?? '.';
        $dateText = FilesystemRowMapper::resolveText($mapping['date'] ?? null, $row);
        $amount = $this->amount($row, $mapping, $decimalSeparator);

        // Totals and footer lines carry an amount and no date (or neither); they are not movements.
        if ($dateText === null && $amount === null) {
            return null;
        }

        $date = $dateText === null ? null : $this->date($dateText, FilesystemRowMapper::resolveText($mapping['date_format'] ?? null, $row));

        if ($date === null || $amount === null) {
            if ($dateText === null) {
                return null;
            }

            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::UNREADABLE_ROW,
                "Row {$rowNumber} has no readable " . ($date === null ? "date ('{$dateText}')" : 'amount') . '.',
            );
        }

        $runningBalance = FilesystemRowMapper::resolveText($mapping['running_balance'] ?? null, $row);

        return new BankStatementLine(
            date: $date,
            amount: $amount,
            description: FilesystemRowMapper::resolveText($mapping['description'] ?? null, $row),
            reference: FilesystemRowMapper::resolveText($mapping['reference'] ?? null, $row),
            runningBalance: $runningBalance === null ? null : $this->number($runningBalance, $decimalSeparator),
            counterparty: FilesystemRowMapper::resolveText($mapping['counterparty'] ?? null, $row),
            raw: $row,
        );
    }

    /**
     * Signed from the company's side. Three shapes, picked by which keys the mapping has: separate
     * debit/credit columns; one amount column whose sign is the bank's; one amount column with a
     * direction column (`$map` it to "debit" / "credit").
     *
     * @param array<string, string> $row
     * @param array<string, mixed> $mapping
     */
    private function amount(array $row, array $mapping, string $decimalSeparator): ?float
    {
        if (array_key_exists('debit', $mapping) || array_key_exists('credit', $mapping)) {
            $debit = $this->number(FilesystemRowMapper::resolveText($mapping['debit'] ?? null, $row), $decimalSeparator);
            $credit = $this->number(FilesystemRowMapper::resolveText($mapping['credit'] ?? null, $row), $decimalSeparator);

            if ($debit === null && $credit === null) {
                return null;
            }

            return abs($credit ?? 0.0) - abs($debit ?? 0.0);
        }

        $amount = $this->number(FilesystemRowMapper::resolveText($mapping['amount'] ?? null, $row), $decimalSeparator);

        if ($amount === null || ! array_key_exists('direction', $mapping)) {
            return $amount;
        }

        $direction = Str::lowerTrim((string) FilesystemRowMapper::resolveText($mapping['direction'], $row));

        return match ($direction) {
            'debit' => -abs($amount),
            'credit' => abs($amount),
            default => $amount,
        };
    }

    /**
     * "RD$ 1,234.56", "(1.234,56)", "1234.56-", "US$1,000" → signed float. The thousands separator is
     * whichever of . and , is not the decimal one.
     */
    private function number(?string $text, string $decimalSeparator): ?float
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

    private function date(string $text, ?string $format): ?Carbon
    {
        if ($format !== null) {
            $parsed = DateHelper::parseExact($text, $format);

            return $parsed === null ? null : Carbon::instance($parsed);
        }

        return DateHelper::parseCarbonStrict($text)?->startOfDay();
    }
}
