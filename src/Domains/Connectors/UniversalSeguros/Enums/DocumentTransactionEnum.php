<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum DocumentTransactionEnum: string
{
    case MATRICULA = 'Matricula';
    case INSPECCION = 'Inspeccion';
    case VIDEO_INSPECCION = 'VideoInspeccion';
    case EMBARAZADA_FORMULARIO = 'EmbarazadaFormulario';
    case PASAPORTE = 'Pasaporte';
    case PASAPORTE_CODEUDOR = 'PasaporteCodeudor';
    case CONDUCE = 'Conduce';
}
