<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemAttachInput;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\SystemModules\DataTransferObject\SystemModuleEntityInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use League\Csv\Reader;

class FilesystemManagementMutation
{
    /**
     * Assign a filesystem to a entity.
     */
    public function attachFile(mixed $rootValue, array $request): string
    {
        $filesystemAttachmentInput = FilesystemAttachInput::viaRequest($request['input']);
        $entityInput = new SystemModuleEntityInput(
            'filesystem',
            $filesystemAttachmentInput->systemModuleUuid,
            $filesystemAttachmentInput->entityId
        );

        $entity = SystemModulesRepository::getEntityFromInput($entityInput, auth()->user());

        $attachFile = new AttachFilesystemAction(
            Filesystem::getByUuid($filesystemAttachmentInput->filesystemUuid),
            $entity
        );

        $fileSystemEntity = $attachFile->execute($filesystemAttachmentInput->fieldName);

        return (string) $fileSystemEntity->uuid;
    }

    /**
     * deAttach a file from filesystem
     */
    public function deAttachFile(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $fileEntity = FilesystemEntities::where('uuid', $request['uuid'])
            ->when(! $user->isAdmin(), function ($query) use ($user) {
                $query->fromCompany($user->getCurrentCompany());
            })
            ->notDeleted()
            ->firstOrFail();

        if ($fileEntity->filesystem->apps_id != $app->getId()) {
            return false;
        }
        $response = $fileEntity->softDelete();

        try {
            $systemModule = $fileEntity->systemModule->model_name;
            $entityData = $systemModule::getById($fileEntity->entity_id);
            //@todo Set the same cache trait to all filesystem entities
            if (method_exists($entityData, 'clearLightHouseCacheJob')) {
                $entityData->clearLightHouseCacheJob();
            }
        } catch (ModelNotFoundException $e) {
        }

        return $response;
    }

    public function deAttachFiles(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $i = 0;

        $fileEntities = FilesystemEntities::whereIn('uuid', $request['uuids'])
            ->when(! $user->isAdmin(), function ($query) use ($user) {
                $query->fromCompany($user->getCurrentCompany());
            })
            ->notDeleted()
            ->get();

        foreach ($fileEntities as $fileEntity) {
            if ($fileEntity->filesystem->apps_id != $app->getId()) {
                continue;
            }

            if ($fileEntity->softDelete()) {
                $i++;

                try {
                    $systemModule = $fileEntity->systemModule->model_name;
                    $entityData = $systemModule::getById($fileEntity->entity_id);
                    //@todo Set the same cache trait to all filesystem entities
                    if (method_exists($entityData, 'clearLightHouseCacheJob')) {
                        $entityData->clearLightHouseCacheJob();
                    }
                } catch (ModelNotFoundException $e) {
                }
            }
        }

        return $i == count($request['uuids']);
    }

    /**
     * Handle file validation logic.
     */
    protected function validateFileSize(\Illuminate\Http\UploadedFile $file, int $defaultSize = 20480): void
    {
        $app = app(Apps::class);
        $maxFileSize = $app->get(AppSettingsEnums::DEFAULT_FILESYSTEM_UPLOAD_FILE_SIZE->getValue()) ?? $defaultSize;

        $validator = Validator::make(
            ['file' => $file],
            ['file' => "required|file|max:$maxFileSize"]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Upload a single file, store it on the server, and return the path.
     */
    public function singleFile(mixed $rootValue, array $request): Filesystem
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request['file'];

        // Validate file
        $this->validateFileSize($file);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $filesystem = new FilesystemServices(app(Apps::class), $company);

        return $filesystem->upload($file, $user);
    }

    /**
     * Upload and process a CSV file, returning its metadata and the filesystem record.
     */
    public function uploadCsv(mixed $rootValue, array $request): array
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request['file'];

        // Validate file
        $this->validateFileSize($file);

        // Save file locally
        $uniqueName = 'csv/' . $file->getClientOriginalName() . uniqid();
        $path = $file->store($uniqueName, 'local');
        $storagePath = storage_path('app/' . $path);

        // Process CSV
        $csv = Reader::createFromPath($storagePath, 'r');
        $csv->setHeaderOffset(0);

        $header = $csv->getHeader();
        $row = $csv->nth(0);

        // Upload to filesystem
        $fileSystem = $this->singleFile($rootValue, $request);

        return [
            'filesystem_id' => $fileSystem->id,
            'row' => $row,
            'header' => $header,
        ];
    }

    /**
     * Upload multiple files, store them on the server, and return their paths.
     */
    public function multiFile(mixed $rootValue, array $request): array
    {
        /** @var \Illuminate\Http\UploadedFile[] $files */
        $files = $request['files'];

        $fileSystems = [];
        foreach ($files as $file) {
            // Validate file
            $this->validateFileSize($file);

            $filesystemService = new FilesystemServices(app(Apps::class));
            $fileSystems[] = $filesystemService->upload($file, auth()->user());
        }

        return $fileSystems;
    }
}
