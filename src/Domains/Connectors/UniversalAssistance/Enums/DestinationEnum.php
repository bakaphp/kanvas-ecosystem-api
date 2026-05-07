<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum DestinationEnum: string
{
    case AFRICA = 'Africa';
    case NORTH_AMERICA = 'America del norte';
    case SOUTH_AMERICA = 'América del Sur (salvo Vzla)';
    case ASIA = 'Asia';
    case BRAZIL = 'Brasil';
    case CENTRAL_AMERICA_CARIBBEAN = 'Centro america/Caribe';
    case CUBA = 'Cuba';
    case EUROPE = 'Europa';
    case INTERNATIONAL_WORLD = 'Internacional Mundo';
    case OCEANIA = 'Oceanía';
    case NATIONAL_TERRITORY = 'Territorio Nacional';
    case VENEZUELA = 'Venezuela';
}
