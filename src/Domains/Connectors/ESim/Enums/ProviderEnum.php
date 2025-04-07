<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Enums;

enum ProviderEnum: string
{
    case E_SIM_GO = 'EsimGo';
    case EASY_ACTIVATION = 'EasyActivations';
    case AIROLA = 'Airola';
    case CMLINK = 'CMLink';
}
