<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Enums;

enum RefundStatusEnum: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
