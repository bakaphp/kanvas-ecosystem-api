<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum VoucherStatusEnum: string
{
    case ACTIVE = 'Activo';
    case ACTIVE_CONSUMPTION = 'Activo consumo';
    case ACTIVE_BILLING = 'Activo facturación';
    case CANCELLED = 'Anulado';
    case INACTIVE = 'Baja';
    case CANCELED = 'Cancelado';
    case DOUBTFUL = 'Dudoso';
    case OBSERVATION_STATUS = 'Estado de observación';
    case NORMAL_STATUS = 'Estado normal';
    case INACTIVE_STATUS = 'Inactivo';
    case INVERTED = 'Invertido';
    case LIQUIDATED = 'Liquidado';
    case NOT_STARTED = 'No iniciado';
    case PENDING = 'Pendiente';
    case PRE_ACTIVE = 'PreActivo';
    case LOSS = 'Pérdida';
    case REJECTED = 'Rechazado';
    case RETAIN = 'Retener';
    case NOT_INITIATED = 'Sin iniciar';
    case SUBSTANDARD = 'Subestándar';
    case EXPIRED = 'Vencido';
}
