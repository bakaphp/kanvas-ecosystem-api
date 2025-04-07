<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Enums;

enum TaskStatusEnum: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case NO_APPLICABLE = 'no_applicable';

    public static function validate(string $status): bool
    {
        return in_array($status, [
            self::PENDING->value,
            self::IN_PROGRESS->value,
            self::COMPLETED->value,
            self::NO_APPLICABLE->value,
        ]);
    }
}
