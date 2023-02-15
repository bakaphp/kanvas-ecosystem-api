<?php

declare(strict_types=1);

namespace Kanvas\Apps\Repositories;

use Kanvas\Apps\Models\Apps;

class AppsRepository
{
    /**
     * Get the default company group for this company on the current app.
     *
     * @param string $key
     *
     * @return Apps|null
     */
    public static function findFirstByKey(string $key): ?Apps
    {
        return Apps::where('key', $key)->notDeleted()->firstOrFail();
    }

    /**
     * Get the default company group for this company on the current app.
     *
     * @param string $domainName
     *
     * @return Apps|null
     */
    public static function getByDomainName(string $domainName): ?Apps
    {
        return Apps::where('domain', $domainName)->notDeleted()->where('domain_based', 1)->first();
    }
}
