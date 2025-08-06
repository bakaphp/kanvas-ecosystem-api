<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum EstadoVoucherEnum: string
{
    case ACTIVO = 'Activo';
    case ACTIVO_CONSUMO = 'Activo consumo';
    case ACTIVO_FACTURACION = 'Activo facturación';
    case ANULADO = 'Anulado';
    case BAJA = 'Baja';
    case CANCELADO = 'Cancelado';
    case DUDOSO = 'Dudoso';
    case ESTADO_OBSERVACION = 'Estado de observación';
    case ESTADO_NORMAL = 'Estado normal';
    case INACTIVO = 'Inactivo';
    case INVERTIDO = 'Invertido';
    case LIQUIDADO = 'Liquidado';
    case NO_INICIADO = 'No iniciado';
    case PENDIENTE = 'Pendiente';
    case PREACTIVO = 'PreActivo';
    case PERDIDA = 'Pérdida';
    case RECHAZADO = 'Rechazado';
    case RETENER = 'Retener';
    case SIN_INICIAR = 'Sin iniciar';
    case SUBESTANDAR = 'Subestándar';
    case VENCIDO = 'Vencido';
}
