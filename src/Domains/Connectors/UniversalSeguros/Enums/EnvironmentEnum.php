<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Enums;

enum EnvironmentEnum: string
{
    case QA = 'qa';
    case PROD = 'prod';

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::QA => 'https://qa.universal.com.do/rest/serviceplattform',
            self::PROD => 'https://api.universal.com.do/rest/serviceplattform',
        };
    }

    public function idpTokenUrl(): string
    {
        return match ($this) {
            self::QA => 'https://idp-qa.azurewebsites.net/connect/token',
            self::PROD => 'https://idp.universal.com.do/connect/token',
        };
    }
}
