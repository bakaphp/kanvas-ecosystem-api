<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

/**
 * The Mercury webhook events we subscribe to (5 of the 7 available).
 *
 * TRANSACTION_UPDATED is the one that's easy to skip and mustn't be: we only ingest `sent` transactions, but
 * a card authorization is CREATED as `pending` and only later UPDATED to `sent` when it settles. Without this
 * event the create arrives in a state we deliberately ignore, and the settlement never arrives at all — every
 * card transaction would be invisible until the nightly poll.
 *
 * Treasury and investment balances are deliberately not subscribed: we model neither, and taking deliveries
 * we discard just burns webhook attempts and fills the log.
 */
enum WebhookEventEnum: string
{
    case TRANSACTION_CREATED = 'transaction.created';
    case TRANSACTION_UPDATED = 'transaction.updated';
    case CHECKING_BALANCE_UPDATED = 'checkingAccount.balance.updated';
    case SAVINGS_BALANCE_UPDATED = 'savingsAccount.balance.updated';
    case CREDIT_BALANCE_UPDATED = 'creditAccount.balance.updated';

    /**
     * @return list<string>
     */
    public static function subscribed(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
