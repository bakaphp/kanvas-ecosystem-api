<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Enums;

enum ConfigurationEnum: string
{
    case PRIVATE_KEY = 'CARDNET_PRIVATE_KEY';
    case PUBLIC_KEY = 'CARDNET_PUBLIC_KEY';
    case BASE_URL = 'CARDNET_BASE_URL';
    case SANDBOX_CURRENCY = 'CARDNET_SANDBOX_CURRENCY';
    case REQUIRES_ACTIVATION = 'CARDNET_REQUIRES_ACTIVATION';

    case SANDBOX_URL = 'https://labservicios.cardnet.com.do/servicios/tokens';
    case PROD_URL = 'https://servicios.cardnet.com.do/servicios/tokens';
}
