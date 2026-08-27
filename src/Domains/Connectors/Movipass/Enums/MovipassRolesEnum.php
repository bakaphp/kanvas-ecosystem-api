<?php

namespace Kanvas\Connectors\Movipass\Enums;

enum MovipassRolesEnum: string
{
    case OPERATIONS = 'operaciones';
    case FINANCE = 'FinanzasOperaciones';
    case AGENT = 'agente';
    case TRUCK_DRIVER = 'gruero';
    case ROADSIDE_ASSISTANCE_OPERATOR = 'roadside_assistance_operator';
    case OPERATOR = 'Operador';
    case RDVIAL_CONSULTANT = 'RdvialConsultant';
    case VIEWER = 'viewer';
    case PARQUEAT = 'parqueat';
}
