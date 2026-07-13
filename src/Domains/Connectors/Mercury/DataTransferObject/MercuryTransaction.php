<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Enums\TransactionKindEnum;
use Kanvas\Connectors\Mercury\Enums\TransactionStatusEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;

/**
 * One Mercury transaction, normalized into Scribe's vocabulary.
 *
 * This class exists to absorb Mercury's two quirks so nothing downstream has to know them:
 *
 *  1. `amount` is SIGNED — negative is money out. Scribe wants an explicit direction plus a positive
 *     magnitude, because a signed amount silently flips the meaning of a debit/credit column.
 *  2. `postedAt` is null until the transaction settles. We ingest settled rows only, but we still fall
 *     back to createdAt rather than risk a null posting date reaching the ledger.
 */
class MercuryTransaction
{
    public function __construct(
        public readonly string $id,
        public readonly string $accountId,
        public readonly BankTransactionDirectionEnum $direction,
        public readonly float $amount,
        public readonly BankTransactionCategoryEnum $category,
        public readonly TransactionStatusEnum $status,
        public readonly Carbon $postedAt,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $memo = null,
        public readonly ?string $kind = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromApi(array $payload): self
    {
        $signedAmount = (float) ($payload['amount'] ?? 0);

        $postedAt = $payload['postedAt'] ?? $payload['createdAt'] ?? null;

        return new self(
            id: (string) $payload['id'],
            accountId: (string) ($payload['accountId'] ?? ''),
            direction: $signedAmount < 0
                ? BankTransactionDirectionEnum::DEBIT
                : BankTransactionDirectionEnum::CREDIT,
            amount: abs($signedAmount),
            category: TransactionKindEnum::categoryFor($payload['kind'] ?? null),
            status: TransactionStatusEnum::tryFrom((string) ($payload['status'] ?? ''))
                ?? TransactionStatusEnum::PENDING,
            postedAt: $postedAt !== null ? Carbon::parse((string) $postedAt) : Carbon::now(),
            counterpartyName: isset($payload['counterpartyName']) && $payload['counterpartyName'] !== ''
                ? (string) $payload['counterpartyName']
                : null,
            memo: self::resolveMemo($payload),
            kind: isset($payload['kind']) ? (string) $payload['kind'] : null,
            raw: $payload,
        );
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Mercury spreads the human-readable description across three optional fields; take the first that
     * actually says something.
     */
    private static function resolveMemo(array $payload): ?string
    {
        foreach (['note', 'externalMemo', 'bankDescription'] as $field) {
            if (! empty($payload[$field])) {
                return (string) $payload[$field];
            }
        }

        return null;
    }
}
