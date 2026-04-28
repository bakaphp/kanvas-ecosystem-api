<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum OrderTypeEnum: string
{
    case MOVIPASS = 'movipass';
    case PASO_RAPIDO = 'paso_rapido';
    case IMPOUND_LOT = 'impound_lot';
    case ROADSIDE_ASSISTANCE = 'roadside_assistance';
    case PARKING_FINE = 'parking_fine';
    case PARKING_SESSION = 'parking_session';
}
