<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mindee\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'MINDEE_API_KEY';
    case ACCOUNT_NAME = 'MINDEE_ACCOUNT_NAME';
}
