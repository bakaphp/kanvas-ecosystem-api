<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

use Spatie\Enum\Enum;

/**
 * @method static self OFF()
 * @method static self ON()
 */
class FollowUpValueEnum extends Enum
{
    protected static function values(): array
    {
        return [
            'OFF' => 0,
            'ON' => 1,
        ];
    }
}
