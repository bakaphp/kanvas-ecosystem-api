<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Filesystem\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;

class FilesystemRepository
{
    /**
     * Register this company to the the following app.
     *
     * @param int $id
     *
     * @return Filesystem
     */
    public static function getById(int $id) : Filesystem
    {
        $app = app(Apps::class);
        return Filesystem::where('id', $id)
                        ->where('apps_id', $app->getKey())
                        ->firstOrFail();
    }
}
