<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Filesystem\Actions;

use Kanvas\Filesystem\Filesystem\Models\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Apps\Models\Apps;

class CreateFilesystemAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected string $filePath
    ) {
    }

    /**
     * 
     *
     * @return Filesystem
     * 
     * @todo Use currentCompanyId instead of defaultCompanyId when setting companies_id
     * @todo why tf does saveOrFail works but not create method?
     */
    public function execute() : Filesystem
    {    
        $fileMetadata = pathinfo($this->filePath);
        $app = app(Apps::class);
        $userData = app('userData');
        $filesystemLocalCDN = config('kanvas.filesystem.local.cdn');

        $fileSystem = new Filesystem();
        $fileSystem->name = $fileMetadata['filename'];
        $fileSystem->companies_id = $userData->defaultCompany->getKey() ?? 1;
        $fileSystem->apps_id = $app->getKey();
        $fileSystem->users_id = $userData->getKey();
        $fileSystem->path = $this->filePath;
        $fileSystem->url = $filesystemLocalCDN . DIRECTORY_SEPARATOR . $this->filePath;
        $fileSystem->file_type = $fileMetadata['extension'];
        $fileSystem->size = (string) Storage::size($this->filePath);
        $fileSystem->created_at = date('Y-m-h');
        $fileSystem->is_deleted = 0;
        $fileSystem->saveOrFail();

        return $fileSystem;
    }
}
