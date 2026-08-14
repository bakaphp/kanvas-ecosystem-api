<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum DocumentOperationEnum: string
{
    case PRE_COTIZACION = 'PreCotizacion';
    case COTIZACION = 'Cotizacion';
}
