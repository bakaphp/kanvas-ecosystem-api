<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Regions\Models\Regions;

abstract class BaseClient
{
    abstract public static function getInstance(AppInterface $app, CompanyInterface $company, Regions $region);

    abstract protected static function createInstance(AppInterface $app, CompanyInterface $company, Regions $region);

    abstract static function getKeys(CompanyInterface $company, AppInterface $app, Regions $region): array;
}
