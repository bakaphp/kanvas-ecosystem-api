<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'RespondIO';
    case BEARER_TOKEN = 'RESPONDIO_BEAR_TOKEN_AUTH';
}
