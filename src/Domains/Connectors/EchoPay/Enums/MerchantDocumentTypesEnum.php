<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantDocumentTypesEnum: string
{
    case DNI = "CEDULA";
    case PASSPORT = "PASAPORTE";
}
