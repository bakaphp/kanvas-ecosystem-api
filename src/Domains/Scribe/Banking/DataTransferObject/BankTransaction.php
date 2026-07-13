<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Spatie\LaravelData\Data;

/**
 * One normalized bank movement, as a connector hands it to Scribe.
 *
 * Connectors are responsible for normalizing their own quirks before building this — signed amounts become
 * an explicit direction + positive amount, vendor-specific txn kinds become a BankTransactionCategoryEnum.
 * Scribe never parses a raw provider payload; it only keeps it in `rawPayload` for audit.
 *
 * Holds Eloquent models (app / company / bankAccount), so per the root convention this DTO must never be a
 * property on a queued job — rebuild it inside handle().
 */
class BankTransaction extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly BankAccount $bankAccount,
        public readonly Carbon $postedAt,
        public readonly Carbon $transactionDate,
        public readonly BankTransactionDirectionEnum $direction,
        public readonly float $amountNative,
        public readonly string $currency,
        public readonly float $amountBase,
        public readonly float $fxRateToBase = 1.0,
        public readonly BankTransactionCategoryEnum $category = BankTransactionCategoryEnum::UNKNOWN,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $counterpartyAccountLast4 = null,
        public readonly ?string $memo = null,
        public readonly ?array $rawPayload = null,
        public readonly string $source = 'kanvas',
        public readonly ?string $externalId = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
