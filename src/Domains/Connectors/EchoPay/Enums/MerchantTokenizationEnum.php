<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantTokenizationEnum: string
{
    case TOKENIZATION_YES = "TOKENIZATION YES";
    case TOKENIZATION_NO = "TOKENIZATION NO";
}
