<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums;

enum MessageIntentEnum: string
{
    case PRICE = 'price';
    case OUT_THE_DOOR = 'out_the_door';
    case PAYMENT = 'payment';
    case ADD_ON = 'add_on';
    case COMPARISON = 'comparison';
}
