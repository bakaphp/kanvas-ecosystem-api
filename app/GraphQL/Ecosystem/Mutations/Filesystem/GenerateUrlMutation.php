<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;

class GenerateUrlMutation
{
    public function __invoke(mixed $root, array $request): string
    {
        $filesystem = Filesystem::getById($request['id'], app(Apps::class));
        $fileystemService = new FilesystemServices(app(Apps::class));
        $disk = $fileystemService->getStorageByDisk();
        return $disk->temporaryUrl(
            $filesystem->path,
            now()->addDays(7),
            [
                        'ResponseContentDisposition' => 'attachment; filename=' . $filesystem->name,
                    ]
        );
    }
}
