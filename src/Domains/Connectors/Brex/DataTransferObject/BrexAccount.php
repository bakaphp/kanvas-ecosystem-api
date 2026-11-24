<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\DataTransferObject;

use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;

/**
 * A Brex account as it lands in `accounting.bank_accounts`: each cash account, plus ONE row for the card
 * program. Brex lists card transactions for all card accounts together (`/v2/transactions/card/primary`), so
 * the card feed has no per-account id to file under — it gets a synthetic one.
 */
class BrexAccount
{
    public const string CURRENCY = 'USD';

    public const string CARD_EXTERNAL_ID = 'card_primary';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isCard,
        public readonly float $currentBalance,
        public readonly float $availableBalance,
        public readonly ?string $accountNumber = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromCashApi(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            name: (string) ($payload['name'] ?? 'Brex Cash'),
            isCard: false,
            currentBalance: self::money($payload['current_balance'] ?? null),
            availableBalance: self::money($payload['available_balance'] ?? null),
            accountNumber: isset($payload['account_number']) ? (string) $payload['account_number'] : null,
            raw: $payload,
        );
    }

    /**
     * @param list<array<string, mixed>> $cardAccounts
     */
    public static function fromCardApi(array $cardAccounts): self
    {
        return new self(
            id: self::CARD_EXTERNAL_ID,
            name: 'Brex Card',
            isCard: true,
            currentBalance: array_sum(array_map(fn (array $row): float => self::money($row['current_balance'] ?? null), $cardAccounts)),
            availableBalance: array_sum(array_map(fn (array $row): float => self::money($row['available_balance'] ?? null), $cardAccounts)),
            raw: ['card_accounts' => $cardAccounts],
        );
    }

    /**
     * Brex `Money` is an integer in the currency's smallest unit (USD cents), not a decimal.
     */
    public static function money(mixed $money): float
    {
        return is_array($money) && isset($money['amount'])
            ? round(((int) $money['amount']) / 100, 2)
            : 0.0;
    }

    public function cashAccountSubType(): AccountSubTypeEnum
    {
        return $this->isCard
            ? AccountSubTypeEnum::CREDIT_CARD_LIABILITY
            : AccountSubTypeEnum::CASH_CHECKING;
    }

    public function last4(): ?string
    {
        return $this->accountNumber !== null && $this->accountNumber !== ''
            ? substr($this->accountNumber, -4)
            : null;
    }
}
