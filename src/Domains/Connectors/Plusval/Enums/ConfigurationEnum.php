<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'PLUSVAL_BASE_URL';
    case API_KEY = 'PLUSVAL_API_KEY';
}
