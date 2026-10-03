<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

/**
 * The "service does not proceed" branch of the intake flow: the operator has to tell the client why
 * the case was not authorized, so the reason is a closed vocabulary rather than free text.
 */
enum RoadsideNotProceedReasonEnum: string
{
    case NO_COVERAGE = 'no_coverage';
    case OUT_OF_ZONE = 'out_of_zone';
    case REJECTED = 'rejected';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NO_COVERAGE => 'No coverage',
            self::OUT_OF_ZONE => 'Out of service zone',
            self::REJECTED => 'Rejected',
            self::OTHER => 'Other',
        };
    }
}
