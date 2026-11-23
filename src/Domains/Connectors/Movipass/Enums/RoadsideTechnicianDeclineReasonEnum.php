<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

/**
 * Spec v6.0 §9.2: the closed list a technician picks from to reject an offer or drop an accepted
 * case. "Other" needs a comment; a cancellation after accepting always does.
 */
enum RoadsideTechnicianDeclineReasonEnum: string
{
    case UNIT_UNAVAILABLE = 'unit_unavailable';
    case OUT_OF_COVERAGE = 'out_of_coverage';
    case INCOMPATIBLE_VEHICLE = 'incompatible_vehicle';
    case TECHNICAL_ISSUE = 'technical_issue';
    case OPERATIONAL_EMERGENCY = 'operational_emergency';
    case OTHER = 'other';
}
