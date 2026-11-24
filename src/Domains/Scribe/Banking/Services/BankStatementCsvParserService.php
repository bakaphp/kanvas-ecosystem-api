<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Support\Str;
use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Kanvas\Imports\Services\MappedCsvService;
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
 * Header detection, row keying, numbers and dates are MappedCsvService's; this class only knows what a row
 * means to a bank statement.
 */
class BankStatementCsvParserService
{
    public function __construct(
        private readonly MappedCsvService $csv = new MappedCsvService(),
    ) {
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function matches(string $contents, array $mapping): bool
    {
        return $this->csv->matches($contents, $mapping);
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function parse(string $contents, array $mapping): ParsedBankStatement
    {
        $rows = $this->csv->rowsUnderHeader($contents, $mapping);

        if ($rows === null) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::NO_ROWS,
                'The mapping\'s columns are not in this file.',
            );
        }

        $statementLines = [];

        foreach ($rows as $number => $row) {
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
        return $this->csv->peek($contents);
    }

    /**
     * Columns a mapping reads, i.e. what a file must contain for the mapping to apply.
     *
     * @param array<string, mixed> $mapping
     * @return list<string>
     */
    public function referencedColumns(array $mapping): array
    {
        return $this->csv->referencedColumns($mapping);
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

        $date = $dateText === null ? null : MappedCsvService::date($dateText, FilesystemRowMapper::resolveText($mapping['date_format'] ?? null, $row));

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
            runningBalance: $runningBalance === null ? null : MappedCsvService::number($runningBalance, $decimalSeparator),
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
            $debit = MappedCsvService::number(FilesystemRowMapper::resolveText($mapping['debit'] ?? null, $row), $decimalSeparator);
            $credit = MappedCsvService::number(FilesystemRowMapper::resolveText($mapping['credit'] ?? null, $row), $decimalSeparator);

            if ($debit === null && $credit === null) {
                return null;
            }

            return abs($credit ?? 0.0) - abs($debit ?? 0.0);
        }

        $amount = MappedCsvService::number(FilesystemRowMapper::resolveText($mapping['amount'] ?? null, $row), $decimalSeparator);

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
}
