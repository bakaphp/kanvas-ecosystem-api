<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

use Baka\Contracts\CompanyInterface;

enum EcfEnvironmentEnum: string
{
    case TEST = 'test';
    case PRODUCTION = 'production';

    /**
     * Anything but an explicit 'production' is test, so a half-configured company never reaches DGII
     * production. Every provider reads the environment through here.
     */
    public static function forCompany(CompanyInterface $company): self
    {
        return self::tryFrom((string) $company->get(ConfigurationEnum::ECF_ENVIRONMENT->value)) === self::PRODUCTION
            ? self::PRODUCTION
            : self::TEST;
    }

    public function isProduction(): bool
    {
        return $this === self::PRODUCTION;
    }
}
