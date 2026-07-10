<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\DataTransferObject;

use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Maps one Acumatica GL Account (dbo.Account) to the shape PullChartOfAccountsAction feeds into
 * Scribe's Create/UpdateAccountAction. Pure — no DB, no lookups; account resolution to Kanvas
 * account ids happens in the pull action.
 *
 * Acumatica classifies accounts by a single-letter Type (A/L/I/E). Equity, COGS and the "other"
 * buckets don't exist as first-class Acumatica types — they live under Liability/Expense/Income
 * and are only distinguishable by AccountClass, which tenants configure freely. We map to the
 * four base Scribe types and leave the finer sub-typing to a human / later refinement.
 */
class AcumaticaImportAccount extends Data
{
    public const string SOURCE = 'acumatica';

    public function __construct(
        public readonly string $externalId,
        public readonly string $accountNumber,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?AccountTypeEnum $accountType,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row raw dbo.Account row (PascalCase columns)
     */
    public static function fromRow(array $row): self
    {
        $accountNumber = trim((string) ($row['AccountCD'] ?? ''));
        $description = trim((string) ($row['Description'] ?? ''));

        return new self(
            externalId: trim((string) ($row['AccountID'] ?? '')),
            accountNumber: $accountNumber,
            name: $description !== '' ? $description : $accountNumber,
            description: $description !== '' ? $description : null,
            accountType: self::mapType((string) ($row['Type'] ?? '')),
        );
    }

    private static function mapType(string $acuType): ?AccountTypeEnum
    {
        $normalized = strtoupper(trim($acuType));

        return match ($normalized[0] ?? '') {
            'A' => AccountTypeEnum::ASSET,
            'L' => AccountTypeEnum::LIABILITY,
            'I' => AccountTypeEnum::REVENUE,
            'E' => AccountTypeEnum::EXPENSE,
            default => null,
        };
    }
}
