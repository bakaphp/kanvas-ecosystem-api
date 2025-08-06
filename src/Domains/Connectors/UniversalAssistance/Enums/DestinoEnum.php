<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum DestinoEnum: string
{
    case AFRICA = 'Africa';
    case AMERICA_DEL_NORTE = 'America del norte';
    case AMERICA_DEL_SUR = 'América del Sur (salvo Vzla)';
    case ASIA = 'Asia';
    case BRASIL = 'Brasil';
    case CENTRO_AMERICA_CARIBE = 'Centro america/Caribe';
    case CUBA = 'Cuba';
    case EUROPA = 'Europa';
    case INTERNACIONAL_MUNDO = 'Internacional Mundo';
    case OCEANIA = 'Oceanía';
    case TERRITORIO_NACIONAL = 'Territorio Nacional';
    case VENEZUELA = 'Venezuela';
}
