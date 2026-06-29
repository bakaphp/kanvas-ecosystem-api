<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum ConfigurationEnum: string
{
    case ENVIRONMENT = 'universal_seguros_environment';
    case CLIENT_ID = 'universal_seguros_client_id';
    case CLIENT_SECRET = 'universal_seguros_client_secret';
    case SCOPES = 'universal_seguros_scopes';

    public const DEFAULT_SCOPES = 'unit.serviceplattform.externos unit.serviceplattform.cotizaciones unit.serviceplattform.polizas unit.serviceplattform.emitir.paratusegurodeley unit.serviceplattform.emitir.paratuauto unit.serviceplattform.emitir.porloqueconduces';
}
