<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum RoadsideIntakeOutcomeEnum: string
{
    case PROCEED = 'proceed';
    case CABIN_REVIEW = 'cabin_review';
    case NOT_PROCEED = 'not_proceed';

    public function weight(): int
    {
        return match ($this) {
            self::PROCEED => 0,
            self::CABIN_REVIEW => 1,
            self::NOT_PROCEED => 2,
        };
    }
}
