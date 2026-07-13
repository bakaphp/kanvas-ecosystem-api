<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;

/**
 * Mercury's 21 transaction kinds, mapped onto Scribe's accounting categories.
 *
 * Only kinds we can name with certainty get a category — fees and internal transfers. Everything else
 * (wires, payments, card spend, deposits) stays UNKNOWN on purpose: those are exactly the movements that
 * SHOULD settle a bill or an invoice, and guessing an account for them would be worse than parking them
 * in Suspense for the matcher to resolve.
 *
 * Mercury exposes no `interest` kind, so interest income can't be auto-classified here. It lands in
 * Suspense, which is the safe outcome — a human or the CFO agent classifies it once.
 */
enum TransactionKindEnum: string
{
    case EXTERNAL_TRANSFER = 'externalTransfer';
    case INTERNAL_TRANSFER = 'internalTransfer';
    case OUTGOING_PAYMENT = 'outgoingPayment';
    case CREDIT_CARD_CREDIT = 'creditCardCredit';
    case CREDIT_CARD_TRANSACTION = 'creditCardTransaction';
    case DEBIT_CARD_CREDIT = 'debitCardCredit';
    case DEBIT_CARD_TRANSACTION = 'debitCardTransaction';
    case CARD_INTERNATIONAL_TRANSACTION_FEE = 'cardInternationalTransactionFee';
    case CARD_INTERNATIONAL_TRANSACTION_FEE_REBATE = 'cardInternationalTransactionFeeRebate';
    case CARD_INTERNATIONAL_TRANSACTION_FEE_REVERSAL = 'cardInternationalTransactionFeeReversal';
    case CARD_INTERNATIONAL_TRANSACTION_FEE_REBATE_REVERSAL = 'cardInternationalTransactionFeeRebateReversal';
    case INCOMING_DOMESTIC_WIRE = 'incomingDomesticWire';
    case CHECK_DEPOSIT = 'checkDeposit';
    case INCOMING_INTERNATIONAL_WIRE = 'incomingInternationalWire';
    case TREASURY_TRANSFER = 'treasuryTransfer';
    case CURRENCY_CLOUD_RETURN = 'currencyCloudReturn';
    case WIRE_FEE = 'wireFee';
    case PERSONAL_BANKING_SUBSCRIPTION_FEE = 'personalBankingSubscriptionFee';
    case BILLING_ENGINE_SUBSCRIPTION_FEE = 'billingEngineSubscriptionFee';
    case EXPENSE_REIMBURSEMENT = 'expenseReimbursement';
    case EXOGENOUS_WIRE_DRAWDOWN = 'exogenousWireDrawdown';
    case OTHER = 'other';

    public function toCategory(): BankTransactionCategoryEnum
    {
        return match ($this) {
            self::WIRE_FEE,
            self::PERSONAL_BANKING_SUBSCRIPTION_FEE,
            self::BILLING_ENGINE_SUBSCRIPTION_FEE,
            self::CARD_INTERNATIONAL_TRANSACTION_FEE,
            self::CARD_INTERNATIONAL_TRANSACTION_FEE_REBATE,
            self::CARD_INTERNATIONAL_TRANSACTION_FEE_REVERSAL,
            self::CARD_INTERNATIONAL_TRANSACTION_FEE_REBATE_REVERSAL => BankTransactionCategoryEnum::BANK_FEE,

            self::INTERNAL_TRANSFER,
            self::TREASURY_TRANSFER => BankTransactionCategoryEnum::TRANSFER,

            default => BankTransactionCategoryEnum::UNKNOWN,
        };
    }

    /**
     * Mercury can ship a kind we don't know yet. An unrecognized kind is not an error — it's just
     * unclassified cash, which the Suspense flow already handles. Never throw on the ingest path.
     */
    public static function categoryFor(?string $kind): BankTransactionCategoryEnum
    {
        return self::tryFrom((string) $kind)?->toCategory() ?? BankTransactionCategoryEnum::UNKNOWN;
    }
}
