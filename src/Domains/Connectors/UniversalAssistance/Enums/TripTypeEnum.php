<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum TripTypeEnum: string
{
    case SINGLE_TRIP = 'Un viaje';
    case MULTIPLE_TRIPS = 'Varios viajes';
}
