<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Actions\CreateFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;

final class Upload
{
    /**
     * Upload a file, store it on the server and return the path.
     *
     * @param  mixed  $root
     * @param  array<string, mixed>  $args
     *
     * @return string|null
     */
    public function __invoke($_, array $args) : Filesystem
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $args['file'];
        $uploadPath = config('filesystems.disks.s3.path');

        $s3ImageName = $file->storePublicly($uploadPath, 's3');

        $createFileSystem = new CreateFilesystemAction($file, Auth::user());

        return $createFileSystem->execute(
            Storage::disk('s3')->url($uploadPath . $s3ImageName),
            $uploadPath
        );
    }
}
