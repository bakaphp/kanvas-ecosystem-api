<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassRolesEnum: string
{
    case OPERATIONS = 'operaciones';
    case FINANCE = 'FinanzasOperaciones';
    case AGENT = 'agente';
    case TRUCK_DRIVER = 'gruero';
}
