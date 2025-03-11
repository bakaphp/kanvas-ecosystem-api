<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Enums;

enum ReceiversStatusEnum: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
