<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Filesystem\Repositories;

use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Kanvas\Apps\Apps\Models\Apps;

class FilesystemRepository
{
    /**
     * Register this company to the the following app.
     *
     * @param int $id
     *
     * @return Filesystem
     */
    public static function getById(int $id) : ?Filesystem
    {
        $app = app(Apps::class);
        return Filesystem::where('id',$id)
                        ->where('apps_id',$app->getKey())
                        ->first();
    }
}
