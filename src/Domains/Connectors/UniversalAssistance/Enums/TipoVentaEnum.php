<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Enums;

enum SaleTypeEnum: string
{
    case ANNUAL = 'Anual';
    case ANNUAL_AUTO_RENEWAL = 'Anual con renovacion automatica';
    case ANNUAL_WITH_RENEWAL = 'Anual con renovación';
    case MONTHLY = 'Mensual';
    case MODULES = 'Módulos';
    case BY_DAYS = 'Por días';
    case WEEKLY = 'Semanal';
}
