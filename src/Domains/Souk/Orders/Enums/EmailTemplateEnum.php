<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

enum EmailTemplateEnum: string
{
    case NEW_ORDER = 'new-order';
    case NEW_ORDER_STORE_OWNER = 'new-order-store-owner';

}
