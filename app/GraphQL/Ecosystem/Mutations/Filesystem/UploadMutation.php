<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\UploadFileAction;
use Kanvas\Filesystem\Models\Filesystem;

class UploadMutation
{
    /**
     * Upload a file, store it on the server and return the path.
     */
    public function singleFile(mixed $rootValue, array $request): Filesystem
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request['file'];
        $app = app(Apps::class);

        $uploadFile = new UploadFileAction(auth()->user(), $app);

        return $uploadFile->execute($file);
    }

    /**
     * Multiple Upload a file, store it on the server and return the path.
     */
    public function multiFile(mixed $rootValue, array $request): array
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $files = $request['files'];
        $fileSystems = [];
        $app = app(Apps::class);

        foreach ($files as $file) {
            $uploadFile = new UploadFileAction(auth()->user(), $app);

            $fileSystems[] = $uploadFile->execute($file);
        }

        return $fileSystems;
    }
}
