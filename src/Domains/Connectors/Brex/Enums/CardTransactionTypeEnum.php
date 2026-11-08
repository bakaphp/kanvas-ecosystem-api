<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Enums;

use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;

enum CardTransactionTypeEnum: string
{
    case PURCHASE = 'PURCHASE';
    case REFUND = 'REFUND';
    case CHARGEBACK = 'CHARGEBACK';
    case REWARDS_CREDIT = 'REWARDS_CREDIT';
    case COLLECTION = 'COLLECTION';
    case BNPL_FEE = 'BNPL_FEE';

    public static function categoryFor(?string $type): BankTransactionCategoryEnum
    {
        return match (self::tryFrom((string) $type)) {
            // The card balance being paid from cash: the other half of the cash feed's CARD_COLLECTION.
            self::COLLECTION => BankTransactionCategoryEnum::TRANSFER,
            self::BNPL_FEE => BankTransactionCategoryEnum::BANK_FEE,
            default => BankTransactionCategoryEnum::UNKNOWN,
        };
    }
}
