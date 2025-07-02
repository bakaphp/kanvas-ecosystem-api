<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum OrderTypeEnum: string
{
    case MOVIPASS = 'movipass';
    case PASO_RAPIDO = 'paso_rapido';
    case IMPOUND_LOT = 'impound_lot';
}
