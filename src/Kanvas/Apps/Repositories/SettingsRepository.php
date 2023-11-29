<?php

declare(strict_types=1);

namespace Kanvas\Apps\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;

class SettingsRepository
{
    /**
     * Get the default company group for this company on the current app.
     *
     * @param string $domainName
     */
    public static function getByName(string $name, Apps $app): ?Settings
    {
        return Settings::where('name', $name)
                ->where('apps_id', $app->getId())->first();
    }
}
