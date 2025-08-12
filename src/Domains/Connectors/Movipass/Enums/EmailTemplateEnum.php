<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum EmailTemplateEnum: string
{
    case TYPE_BLANK = 'blank';

    case PAYMENT_RECEIPT = 'payment_receipt';
    case ORDERS_EXPORT = 'orders_export';
}
