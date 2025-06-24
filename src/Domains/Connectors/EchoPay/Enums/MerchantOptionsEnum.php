<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantOptionsEnum: string
{
    case MERCHANT_CATEGORY = "1";
    case MERCHANT_CARD_IDENTIFIER = "2";
    case MERCHANT_PLATFORM = "3";
    case MERCHANT_CUSTOMER_ID = "4";
    case MERCHANT_TOKENIZATION = "27";
    case MERCHANT_DOCUMENT_TYPE = "28";
    case MERCHANT_DOCUMENT_NUMBER = "29";
}
