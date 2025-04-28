<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Enums;

enum ConfigurationEnum: string
{
    case ELEAD_API_KEY = 'ELEAD_API_KEY';
    case ELEAD_API_SECRET = 'ELEAD_API_SECRET';
    case ELEAD_DEV_MODE = 'ELEAD_DEV_MODE';
}
