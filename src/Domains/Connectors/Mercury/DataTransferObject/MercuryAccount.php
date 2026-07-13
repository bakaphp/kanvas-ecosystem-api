<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;

/**
 * One Mercury account, normalized. Mercury is USD-only and exposes no currency field, so we hardcode it
 * rather than inventing a value the API never gives us.
 */
class MercuryAccount
{
    public const string CURRENCY = 'USD';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $accountNumber,
        public readonly string $routingNumber,
        public readonly string $status,
        public readonly string $type,
        public readonly float $currentBalance,
        public readonly float $availableBalance,
        public readonly ?string $kind = null,
        public readonly ?string $nickname = null,
        public readonly ?string $legalBusinessName = null,
        public readonly ?Carbon $createdAt = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromApi(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            name: (string) ($payload['name'] ?? 'Mercury Account'),
            accountNumber: (string) ($payload['accountNumber'] ?? ''),
            routingNumber: (string) ($payload['routingNumber'] ?? ''),
            status: (string) ($payload['status'] ?? 'active'),
            type: (string) ($payload['type'] ?? 'mercury'),
            currentBalance: (float) ($payload['currentBalance'] ?? 0),
            availableBalance: (float) ($payload['availableBalance'] ?? 0),
            kind: isset($payload['kind']) ? (string) $payload['kind'] : null,
            nickname: isset($payload['nickname']) ? (string) $payload['nickname'] : null,
            legalBusinessName: isset($payload['legalBusinessName']) ? (string) $payload['legalBusinessName'] : null,
            createdAt: isset($payload['createdAt']) ? Carbon::parse((string) $payload['createdAt']) : null,
            raw: $payload,
        );
    }

    public const string KIND_CREDIT = 'credit';

    /**
     * A Mercury credit card, from `GET /credit`. That endpoint returns a far thinner object than /accounts —
     * no name, no accountNumber, no type, no kind — so everything else is synthesized here.
     *
     * Its `currentBalance` is NEGATIVE when you owe money (−1130.55 = $1,130.55 outstanding), which is the
     * mirror of a deposit account. We store Mercury's number as-is rather than flipping it, so the row always
     * says exactly what the bank says.
     */
    public static function fromCreditApi(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            name: 'Mercury Credit',
            accountNumber: '',
            routingNumber: '',
            status: (string) ($payload['status'] ?? 'active'),
            type: 'mercury',
            currentBalance: (float) ($payload['currentBalance'] ?? 0),
            availableBalance: (float) ($payload['availableBalance'] ?? 0),
            kind: self::KIND_CREDIT,
            createdAt: isset($payload['createdAt']) ? Carbon::parse((string) $payload['createdAt']) : null,
            raw: $payload,
        );
    }

    /**
     * Which GL account backs this bank account.
     *
     * Mercury tells us `kind`, so use it. A tenant holding checking + savings would otherwise have savings
     * movements land in Cash — Checking and neither GL balance would ever tie back to the real bank.
     *
     * A credit card is NOT cash — it's money you owe, so it backs a LIABILITY account. Spending on it
     * credits (increases) the liability; paying it down debits (decreases) it. That falls out of the normal
     * direction handling for free, because the card's own feed reports a purchase as money out and a payment
     * as money in.
     */
    public function cashAccountSubType(): AccountSubTypeEnum
    {
        return match ($this->kind) {
            self::KIND_CREDIT => AccountSubTypeEnum::CREDIT_CARD_LIABILITY,
            'savings' => AccountSubTypeEnum::CASH_SAVINGS,
            'moneyMarket', 'money_market' => AccountSubTypeEnum::CASH_MONEY_MARKET,
            default => AccountSubTypeEnum::CASH_CHECKING,
        };
    }

    /**
     * Only real Mercury deposit accounts become bank accounts on the books. `external` and `recipient`
     * rows are counterparties Mercury tracks for us — they are not our cash.
     */
    public function isOurs(): bool
    {
        return $this->type === 'mercury' && $this->status === 'active';
    }

    public function displayName(): string
    {
        return $this->nickname ?? $this->name;
    }

    public function last4(): ?string
    {
        return $this->accountNumber !== ''
            ? substr($this->accountNumber, -4)
            : null;
    }
}
