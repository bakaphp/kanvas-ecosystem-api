<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Enums;

enum CartStatusEnum: string
{
    case PENDING = 'pending';
    case ABANDONED = 'abandoned';
    case RECOVERED = 'recovered';
}
