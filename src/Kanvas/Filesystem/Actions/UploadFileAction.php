<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\ImageOptimizerService;
use Kanvas\Users\Models\Users;

class UploadFileAction
{
    public function __construct(
        protected Users $user,
        protected AppInterface $app
    ) {
    }

    public function execute(UploadedFile $file): Filesystem
    {
        if ($this->app->get('filesystem-optimize-on-upload')) {
            $maxWidth = (int) ($this->app->get('filesystem-optimize-max-width') ?: 0) ?: null;
            $maxHeight = (int) ($this->app->get('filesystem-optimize-max-height') ?: 0) ?: null;
            $quality = (int) ($this->app->get('filesystem-optimize-quality') ?: 0) ?: null;

            ImageOptimizerService::optimizeLocalFile(
                filePath: $file->getRealPath(),
                optimize: true,
                maxWidth: $maxWidth,
                maxHeight: $maxHeight,
                quality: $quality,
            );
        }

        $uploadPath = config('filesystems.disks.s3.path');

        $s3ImageName = $file->storePublicly($uploadPath, 's3');

        $createFileSystem = new CreateFilesystemAction($file, $this->user, $this->app);

        return $createFileSystem->execute(
            Storage::disk('s3')->url($uploadPath . $s3ImageName),
            $uploadPath
        );
    }
}
