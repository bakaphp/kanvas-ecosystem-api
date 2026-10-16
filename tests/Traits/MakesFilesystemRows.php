<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;

/**
 * A stored file row without an upload: the file tools only need the tenant columns, a name and a url.
 */
trait MakesFilesystemRows
{
    protected function makeFilesystemRow(int $companyId, string $name): Filesystem
    {
        $file = new Filesystem();
        $file->apps_id = app(Apps::class)->getId();
        $file->companies_id = $companyId;
        $file->users_id = auth()->user()->getId();
        $file->name = $name;
        $file->path = 'test/' . uniqid() . '/' . $name;
        $file->url = 'https://cdn.example.test/' . uniqid() . '/' . $name;
        $file->size = '10';
        $file->file_type = pathinfo($name, PATHINFO_EXTENSION);
        $file->is_deleted = 0;
        $file->saveOrFail();

        return $file;
    }
}
