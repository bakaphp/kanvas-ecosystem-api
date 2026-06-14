<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\UploadedFile;
use Kanvas\Enums\AppEnums;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateFilesystemAction
{
    public bool $runWorkflow = true;

    public function __construct(
        protected UploadedFile $file,
        protected Users $user,
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
    }

    public function execute(string $uploadUrl, string $uploadPath): Filesystem
    {
        $app = $this->app;
        $companyId = $this->company ? $this->company->getId() : $this->user->getCurrentCompany()->getKey();
        $fileSystem = new Filesystem();
        $fileSystem->name = $this->file->getClientOriginalName();
        $fileSystem->companies_id = $companyId ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $fileSystem->apps_id = $app->getKey();
        $fileSystem->users_id = $this->user->getKey();
        $fileSystem->path = $uploadPath;
        $fileSystem->url = $uploadUrl;
        $fileSystem->file_type = $this->resolveFileType();
        $fileSystem->size = $this->file->getSize();
        $fileSystem->saveOrFail();

        if ($this->runWorkflow) {
            $fileSystem->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true
            );
        }

        $fileSystem->refresh();

        return $fileSystem;
    }

    /**
     * Resolve the stored file_type. For images — the formats ImageMagick/GD will later decode —
     * trust the magic bytes so a file claiming a `.heic`/`.png` extension but carrying crafted
     * content can't steer the decoder (e.g. ConvertHeicToJpgActivity branches on file_type).
     * For non-image types (docs, csv, zip-based Office formats whose magic bytes are ambiguous)
     * keep the client extension, which is only used for display.
     */
    private function resolveFileType(): string
    {
        $clientExtension = strtolower($this->file->getClientOriginalExtension());
        $realPath = $this->file->getRealPath();

        if ($realPath === false) {
            return $clientExtension ?: 'unknown';
        }

        $mimeType = FilesystemServices::detectMimeType($realPath);

        if (! str_starts_with($mimeType, 'image/')) {
            return $clientExtension ?: 'unknown';
        }

        $detectedExtension = FilesystemServices::getExtensionFromMimeType($mimeType);

        return $detectedExtension !== 'bin'
            ? $detectedExtension
            : ($clientExtension ?: 'unknown');
    }
}
