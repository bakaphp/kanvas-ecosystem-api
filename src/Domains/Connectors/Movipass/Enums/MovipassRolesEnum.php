<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassRolesEnum: string
{
    case ADMIN = 'admin';
    case OPERATIONS = 'operaciones';
    case FINANCE = 'FinanzasOperaciones';
    case AGENT = 'agente';
    case USERS = 'users';
    case TRUCK_DRIVER = 'gruero';
}
