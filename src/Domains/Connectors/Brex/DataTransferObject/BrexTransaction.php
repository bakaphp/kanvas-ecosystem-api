<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Brex\Enums\CardTransactionTypeEnum;
use Kanvas\Connectors\Brex\Enums\CashTransactionTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;

/**
 * One Brex cash or card transaction, normalized into Scribe's vocabulary: explicit direction plus a positive
 * magnitude in dollars, where Brex sends a signed integer in cents.
 *
 * The sign means opposite things on the two feeds. On cash, negative is money out. On the card, Brex reports
 * from the card's side: a purchase is positive (the balance owed grows) and a refund or payment is negative —
 * so the card's sign is flipped to get "money out of the business" as a DEBIT, the convention the card's
 * liability bank account already uses for Mercury.
 */
class BrexTransaction
{
    public function __construct(
        public readonly string $id,
        public readonly BankTransactionDirectionEnum $direction,
        public readonly float $amount,
        public readonly string $currency,
        public readonly BankTransactionCategoryEnum $category,
        public readonly Carbon $postedAt,
        public readonly string $description,
        public readonly ?string $type = null,
        public readonly ?string $counterpartyName = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromCashApi(array $payload): self
    {
        $signedCents = (int) ($payload['amount']['amount'] ?? 0);
        $type = isset($payload['type']) ? (string) $payload['type'] : null;

        return new self(
            id: (string) $payload['id'],
            direction: $signedCents < 0 ? BankTransactionDirectionEnum::DEBIT : BankTransactionDirectionEnum::CREDIT,
            amount: round(abs($signedCents) / 100, 2),
            currency: strtoupper((string) ($payload['amount']['currency'] ?? BrexAccount::CURRENCY)),
            category: CashTransactionTypeEnum::categoryFor($type),
            postedAt: self::postedAt($payload),
            description: (string) ($payload['description'] ?? ''),
            type: $type,
            raw: $payload,
        );
    }

    public static function fromCardApi(array $payload): self
    {
        $signedCents = (int) ($payload['amount']['amount'] ?? 0);
        $type = isset($payload['type']) ? (string) $payload['type'] : null;
        $merchant = $payload['merchant']['raw_descriptor'] ?? null;

        return new self(
            id: (string) $payload['id'],
            direction: $signedCents > 0 ? BankTransactionDirectionEnum::DEBIT : BankTransactionDirectionEnum::CREDIT,
            amount: round(abs($signedCents) / 100, 2),
            currency: strtoupper((string) ($payload['amount']['currency'] ?? BrexAccount::CURRENCY)),
            category: CardTransactionTypeEnum::categoryFor($type),
            postedAt: self::postedAt($payload),
            description: (string) ($payload['description'] ?? ''),
            type: $type,
            counterpartyName: $merchant !== null && $merchant !== '' ? (string) $merchant : null,
            raw: $payload,
        );
    }

    /**
     * A cash row's `amount` is nullable in Brex's schema; one without it carries nothing to book.
     */
    public function hasAmount(): bool
    {
        return isset($this->raw['amount']['amount']);
    }

    private static function postedAt(array $payload): Carbon
    {
        $date = $payload['posted_at_date'] ?? $payload['initiated_at_date'] ?? null;

        return $date !== null ? Carbon::parse((string) $date) : Carbon::now();
    }
}
