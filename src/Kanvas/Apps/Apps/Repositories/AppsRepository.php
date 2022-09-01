<?php

declare(strict_types=1);

namespace Kanvas\Apps\Apps\Repositories;

use Kanvas\Apps\Apps\Models\Apps;

class AppsRepository
{
    /**
     * Get the default company group for this company on the current app.
     *
     * @param string $key
     *
     * @return Apps
     */
    public static function findFirstByKey(string $key) : ?Apps
    {
        return Apps::where('key', $key)->where('is_deleted', 0)->first();
    }

    /**
     * Get the default company group for this company on the current app.
     *
     * @param string $domainName
     *
     * @return Apps
     */
    public static function getByDomainName(string $domainName) : ?Apps
    {
        return Apps::where('domain', $domainName)->where('domain_based', 1)->first();
    }
}
