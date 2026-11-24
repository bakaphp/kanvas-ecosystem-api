<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Enums;

use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;

enum EnvironmentEnum: string
{
    case TEST = 'test';
    case PRODUCTION = 'production';

    public static function forCompany(CompanyInterface $company): self
    {
        return self::fromEcf(EcfEnvironmentEnum::forCompany($company));
    }

    public static function fromEcf(EcfEnvironmentEnum $environment): self
    {
        return $environment->isProduction() ? self::PRODUCTION : self::TEST;
    }

    public function baseUrl(HostEnum $host): string
    {
        return match ([$this, $host]) {
            [self::TEST, HostEnum::ISSUING] => 'https://fedotest-api.webposonline.com',
            [self::TEST, HostEnum::RECEPTION] => 'https://fedotest-api02.webposonline.com',
            [self::PRODUCTION, HostEnum::ISSUING] => 'https://fedo-api.webposonline.com',
            [self::PRODUCTION, HostEnum::RECEPTION] => 'https://fedo-api02.webposonline.com',
        };
    }

    public function pathSegment(): string
    {
        return $this === self::PRODUCTION ? 'prod' : 'test';
    }

    /**
     * WebPOS `iAmb`: 1 production, 2 test (3 certification is never used by us).
     */
    public function iAmb(): int
    {
        return $this === self::PRODUCTION ? 1 : 2;
    }
}
