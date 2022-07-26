<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Filesystem\Actions;

use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Apps\Models\Apps;
use Exception;

class UploadFileAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected UploadedFile $file
    ) {
    }

    /**
     * Upload file.
     *
     * @return string
     * 
     * @todo Change Exception for custom type of exception
     */
    public function execute() : string
    {
        $app = app(Apps::class);
        $userData = app('userData');
        $filesystemLocalCDN = config('kanvas.filesystem.local.cdn');

       $filePath = Storage::put('files', $this->file);

        if (!$filePath) {
            throw new Exception('Could not upload file');
        }

        return $filePath;
    }
}
