<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Users\Models\Users;

class UploadFileAction
{
    public function __construct(
        protected Users $user,
        protected AppInterface $app
    ) {
    }

    /**
     * Upload.
     */
    public function execute(UploadedFile $file): Filesystem
    {
        $uploadPath = config('filesystems.disks.s3.path');

        $s3ImageName = $file->storePublicly($uploadPath, 's3');

        $createFileSystem = new CreateFilesystemAction($file, $this->user, $this->app);

        return $createFileSystem->execute(
            Storage::disk('s3')->url($uploadPath . $s3ImageName),
            $uploadPath
        );
    }
}
