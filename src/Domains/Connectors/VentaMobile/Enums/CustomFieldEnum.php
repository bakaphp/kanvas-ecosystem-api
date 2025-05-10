<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Enums;

enum CustomFieldEnum: string
{
    case VENTAMOBILE_SOURCE_ID = 'ventamobile_source';
    case VENTAMOBILE_PRODUCT_ID = 'ventamobile_product_id';
}
