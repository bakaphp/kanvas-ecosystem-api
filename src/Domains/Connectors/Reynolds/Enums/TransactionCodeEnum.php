<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Enums;

enum TransactionCodeEnum: string
{
    case INSERT_SALES_LEAD = 'ISL';
    case UPDATE_SALES_LEAD = 'USL';
    case LEAD_UPDATE = 'LDU';
    case DISPOSITION = 'DSP';
    case COMPLETED_ACTIVITY = 'ACT';
}
