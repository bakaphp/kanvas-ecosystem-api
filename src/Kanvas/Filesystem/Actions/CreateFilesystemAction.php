<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Users\Models\Users;

class CreateFilesystemAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected UploadedFile $file,
        protected Users $user
    ) {
    }

    /**
     * Create a new FileSystem
     *
     * @param string $uploadUrl
     * @param string $uploadPath
     * @return Filesystem
     */
    public function execute(string $uploadUrl, string $uploadPath) : Filesystem
    {
        $app = app(Apps::class);

        $fileSystem = new Filesystem();
        $fileSystem->name = $this->file->getClientOriginalName();
        $fileSystem->companies_id = $this->user->defaultCompany->getKey() ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $fileSystem->apps_id = $app->getKey();
        $fileSystem->users_id = $this->user->getKey();
        $fileSystem->path = $uploadPath;
        $fileSystem->url = $uploadUrl;
        $fileSystem->file_type = $this->file->getClientOriginalExtension();
        $fileSystem->size = $this->file->getSize();
        $fileSystem->saveOrFail();

        return $fileSystem;
    }
}
