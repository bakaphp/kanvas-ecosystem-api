<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Http\UploadedFile;
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
        protected Users $user,
        protected AppInterface $app
    ) {
    }

    /**
     * Create a new FileSystem.
     */
    public function execute(string $uploadUrl, string $uploadPath): Filesystem
    {
        $app = $this->app;

        $fileSystem = new Filesystem();
        $fileSystem->name = $this->file->getClientOriginalName();
        $fileSystem->companies_id = $this->user->getCurrentCompany()->getKey() ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $fileSystem->apps_id = $app->getKey();
        $fileSystem->users_id = $this->user->getKey();
        $fileSystem->path = $uploadPath;
        $fileSystem->url = $uploadUrl;
        $fileSystem->file_type = $this->file->guessExtension();
        $fileSystem->size = $this->file->getSize();
        $fileSystem->saveOrFail();

        return $fileSystem;
    }
}
