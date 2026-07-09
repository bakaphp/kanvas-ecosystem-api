<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Enums;

enum ConfigurationEnum: string
{
    case ACUMATICA_CONFIG = 'ACUMATICA_CONFIG';
    case ACUMATICA_DEFAULT_WAREHOUSE = 'ACUMATICA_DEFAULT_WAREHOUSE';
    case ACUMATICA_WRITE_ENABLED = 'ACUMATICA_WRITE_ENABLED';
}
