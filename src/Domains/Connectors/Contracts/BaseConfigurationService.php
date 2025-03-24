<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Regions\Models\Regions;

abstract class BaseConfigurationService
{
    abstract public static function setup(IntegrationDtoInterface $dto): bool;

    abstract public static function generateCredentialKey(CompanyInterface $company, AppInterface $app, Regions $region): string;
}
