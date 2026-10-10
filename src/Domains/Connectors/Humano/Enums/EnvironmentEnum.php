<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Enums;

enum EnvironmentEnum: string
{
    case DEV = 'dev';
    case PROD = 'prod';

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::DEV => 'https://devapi.humano.com.do/api',
            self::PROD => 'https://huapi.humano.com.do/api',
        };
    }
}
