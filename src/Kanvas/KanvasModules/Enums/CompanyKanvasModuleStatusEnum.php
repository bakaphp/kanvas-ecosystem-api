<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Enums;

enum CompanyKanvasModuleStatusEnum: string
{
    case NOT_CONNECTED = 'not_connected';
    case PARTIAL = 'partial';
    case CONNECTED = 'connected';
}
