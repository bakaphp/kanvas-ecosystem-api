<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Enums;

use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;

/**
 * Only types we can name with certainty get a category. PAYMENT (ACH, wire, check in or out) stays UNKNOWN on
 * purpose: those are the movements that should settle a bill or an invoice, so the matcher decides.
 */
enum CashTransactionTypeEnum: string
{
    case PAYMENT = 'PAYMENT';
    case DIVIDEND = 'DIVIDEND';
    case FEE = 'FEE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case INTEREST = 'INTEREST';
    case CARD_COLLECTION = 'CARD_COLLECTION';
    case REWARDS_REDEMPTION = 'REWARDS_REDEMPTION';
    case RECEIVABLES_OFFERS_ADVANCE = 'RECEIVABLES_OFFERS_ADVANCE';
    case FBO_TRANSFER = 'FBO_TRANSFER';
    case RECEIVABLES_OFFERS_REPAYMENT = 'RECEIVABLES_OFFERS_REPAYMENT';
    case RECEIVABLES_OFFERS_COLLECTION = 'RECEIVABLES_OFFERS_COLLECTION';
    case BREX_OPERATIONAL_TRANSFER = 'BREX_OPERATIONAL_TRANSFER';
    case INTRA_CUSTOMER_ACCOUNT_BOOK_TRANSFER = 'INTRA_CUSTOMER_ACCOUNT_BOOK_TRANSFER';
    case BOOK_TRANSFER = 'BOOK_TRANSFER';
    case CRYPTO_BRIDGE = 'CRYPTO_BRIDGE';
    case STABLECOIN = 'STABLECOIN';
    case TRANSACTION_FEES_COLLECTION = 'TRANSACTION_FEES_COLLECTION';
    case PAYBACK = 'PAYBACK';

    public static function categoryFor(?string $type): BankTransactionCategoryEnum
    {
        return match (self::tryFrom((string) $type)) {
            self::FEE, self::TRANSACTION_FEES_COLLECTION => BankTransactionCategoryEnum::BANK_FEE,
            self::INTEREST => BankTransactionCategoryEnum::INTEREST_INCOME,
            // Paying the Brex card from Brex cash moves money between two accounts we own; it is not a spend.
            self::CARD_COLLECTION,
            self::INTRA_CUSTOMER_ACCOUNT_BOOK_TRANSFER,
            self::BOOK_TRANSFER => BankTransactionCategoryEnum::TRANSFER,
            default => BankTransactionCategoryEnum::UNKNOWN,
        };
    }
}
