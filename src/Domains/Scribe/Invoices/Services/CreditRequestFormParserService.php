<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

// Parses NZXT's "Credit Request Form" (CNR) — a fixed-layout Excel template — by label/column position rather than AI extraction, since it's a known template, not a free-form scanned document.
class CreditRequestFormParserService
{
    /**
     * @return array{customer_name: string, region: ?string, tenant: ?string, request_reference_no: string, lines: list<array{control_account_number: string, description: string, amount: float}>, total: float}
     */
    public static function parse(string $localFilePath): array
    {
        $dumper = new class () implements ToArray {
            public function array(array $array): void
            {
            }
        };

        $rows = (Excel::toArray($dumper, $localFilePath))[0] ?? [];

        $customerName = self::findLabelValue($rows, 'Customer Name');
        $requestReferenceNo = self::findLabelValue($rows, 'Request Reference No');

        if ($customerName === null || $requestReferenceNo === null) {
            throw new RuntimeException(
                'Could not find "Customer Name" / "Request Reference No" in this Credit Request Form.'
            );
        }

        $lines = self::parseLines($rows);

        return [
            'customer_name' => $customerName,
            'region' => self::findLabelValue($rows, 'Region'),
            'tenant' => self::findLabelValue($rows, 'Tenant'),
            'request_reference_no' => $requestReferenceNo,
            'lines' => $lines,
            'total' => round(array_sum(array_column($lines, 'amount')), 2),
        ];
    }

    // A row can carry a second label/value pair further right (e.g. "Request Date:" beside "Customer Name:"), so every cell is checked, not just column A.
    private static function findLabelValue(array $rows, string $label): ?string
    {
        $needle = mb_strtolower(rtrim($label, ": \t"));

        foreach ($rows as $row) {
            foreach ($row as $col => $cell) {
                if (! is_string($cell) || mb_strtolower(rtrim(trim($cell), ": \t")) !== $needle) {
                    continue;
                }

                $value = $row[$col + 1] ?? null;
                $value = is_string($value) ? trim($value) : $value;

                return $value !== null && $value !== '' ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{control_account_number: string, description: string, amount: float}>
     */
    private static function parseLines(array $rows): array
    {
        $headerRow = null;
        foreach ($rows as $index => $row) {
            if (is_string($row[0] ?? null) && mb_strtolower(trim($row[0])) === 'control acct#') {
                $headerRow = $index;

                break;
            }
        }

        if ($headerRow === null) {
            return [];
        }

        $lines = [];
        // headerRow + 1 is the "Product number / Product name / Qty / Unit Price" sub-header — data starts after it.
        foreach ($rows as $index => $row) {
            if ($index <= $headerRow + 1) {
                continue;
            }

            $controlAcct = $row[0] ?? null;
            if (! is_string($controlAcct) || trim($controlAcct) === '') {
                break;
            }

            $accountNumber = self::extractAccountNumber($controlAcct);
            if ($accountNumber === null) {
                continue;
            }

            $qty = is_numeric($row[3] ?? null) ? (float) $row[3] : 0.0;
            $unitPrice = is_numeric($row[4] ?? null) ? (float) $row[4] : 0.0;

            $lines[] = [
                'control_account_number' => $accountNumber,
                'description' => trim(trim((string) ($row[1] ?? '')) . ' - ' . trim((string) ($row[2] ?? '')), ' -'),
                'amount' => round($qty * $unitPrice, 2),
            ];
        }

        return $lines;
    }

    // The account number rides as a trailing "-41045"-style suffix on the label itself (e.g. "Promotion Discount -41045", "MDF-72300").
    private static function extractAccountNumber(string $controlAcct): ?string
    {
        return preg_match('/-\s*(\d{4,6})\s*$/', trim($controlAcct), $matches) === 1 ? $matches[1] : null;
    }
}
