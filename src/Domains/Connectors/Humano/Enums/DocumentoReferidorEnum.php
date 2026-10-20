<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

enum DocumentoReferidorEnum: string
{
    case CEDULA = 'CED';
    case RNC = 'RNC';
    case PASAPORTE = 'PAS';
}
