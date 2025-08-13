<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Baka\Helpers\MergePdf;
use Baka\Support\Str;
use Baka\Validations\Pdf;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

        $fileSystemEntity = $attachFile->execute(
            fieldName: $filesystemAttachmentInput->fieldName,
            weight: $filesystemAttachmentInput->weight
        );

        return (string) $fileSystemEntity->uuid;
    }

    public function createFileSystemFromUrl(mixed $rootValue, array $request): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Parse the URL
        if (! filter_var($request['input']['url'], FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The provided URL is not valid.');
        }

        $parsedUrl = parse_url($request['input']['url']);
        $path = $parsedUrl['path']; // Returns: /api/webhooks/upload/file.pdf

        // Get file info from the path
        $pathInfo = pathinfo($path);
        $filetype = $pathInfo['extension'] ?? 'unknown'; // Returns: pdf

        return Filesystem::firstOrCreate(
            [
                'url' => $request['input']['url'],
                'companies_id' => $company->getKey(),
                'apps_id' => $app->getKey(),
            ],
            [
                'name' => $request['input']['name'],
                'users_id' => $user->getId(),
                'path' => $path,
                'file_type' => $filetype,
                'size' => 0,
            ]
        );
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

        $systemModule = $fileEntity->systemModule->model_name;
        $entityId = $fileEntity->entity_id;

        $response = $fileEntity->softDelete();

        try {
            $entityData = $systemModule::getById($entityId);
            //@todo Set the same cache trait to all filesystem entities
            if (method_exists($entityData, 'clearLightHouseCacheJob')) {
                $entityData->clearLightHouseCacheJob();
            }
            $entityData->fireObserverEvent('saved');
        } catch (ModelNotFoundException $e) {
            report($e);
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

        try {
            $csv->setHeaderOffset(0);

            $header = $csv->getHeader();
            $row = $csv->nth(0);
        } catch (\Throwable $th) {
            $result = $this->processCsvWithoutHeaders($storagePath);
            $row = $result['row'];
            $header = $result['header'];
        }

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

    public function deleteFile(mixed $rootValue, array $request): bool
    {
        $filesystem = Filesystem::when(! auth()->user()->isAdmin(), function ($query) {
            $query->where('users_id', auth()->user()->getId())
            ->where('companies_id', auth()->user()->getCurrentCompany()->getId());
        })->where('uuid', $request['uuid'])
            ->notDeleted()
            ->firstOrFail();

        $filesystemService = new FilesystemServices(app(Apps::class));

        if ($filesystemService->delete($filesystem)) {
            return $filesystem->delete();
        }

        return false;
    }

    public function mergeFiles(mixed $rootValue, array $request): ?Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if (count($request['files']) > 0) {
            $files = [];
            foreach ($request['files'] as $fileId) {
                try {
                    $fileUrl = FilesystemEntities::getById($fileId)->filesystem()->where('apps_id', $app->getId())
                        ->where('companies_id', $company->getId())
                        ->firstOrFail()
                        ->url;

                    if (Str::contains($fileUrl, 'jpg')
                        || Str::contains($fileUrl, 'png')
                        || Str::contains($fileUrl, 'jpeg')
                        || Str::contains($fileUrl, 'pdf')) {
                        if (Str::contains($fileUrl, 'pdf')) {
                            if (Pdf::isValidFile($fileUrl)) {
                                $files[] = $fileUrl;
                            }
                        } else {
                            $files[] = $fileUrl;
                        }
                    }
                } catch (Exception $e) {
                }
            }

            if (empty($files)) {
                throw new Exception('No valid files to merge');
            }

            $mergePDF = new MergePdf($app, ...$files);
            $mergeFileName = tempnam(sys_get_temp_dir(), 'mergefile-') . '.pdf';

            $mergePDF->merge($mergeFileName);

            // Create an UploadedFile from the temporary merged PDF
            $uploadedFile = new UploadedFile(
                $mergeFileName,                      // Path to the file
                'merged_file.pdf',                   // Original file name
                'application/pdf',                   // MIME type
                null,                               // Error (null means no error)
                true                                // Mark it as a test file (will not delete original file)
            );

            // Use FilesystemServices to upload the merged file
            $filesystemService = new FilesystemServices($app, $company);
            $uploadedFilesystem = $filesystemService->upload($uploadedFile, $user);

            // Clean up the temporary file
            unlink($mergeFileName);

            return $uploadedFilesystem;
        }

        return null;
    }

    public function renameFile(mixed $rootVale, array $request): Filesystem
    {
        $filesystem = Filesystem::getById($request['id'], app(Apps::class));
        if ($filesystem->users_id != auth()->user()->getId() && ! auth()->user()->isAdmin()) {
            throw new ModelNotFoundException('File not found or you do not have permission to rename it.');
        }
        $filesystem->update([
            'name' => $request['name'],
        ]);

        return $filesystem;
    }

    public function processCsvWithoutHeaders($storagePath): array
    {
        $csv = Reader::createFromPath($storagePath, 'r');

        $allRecords = iterator_to_array($csv->getRecords());

        if (empty($allRecords)) {
            return [
                'headers' => [],
                'firstRow' => []
            ];
        }

        $firstRecord = array_values($allRecords[0]);

        $headers = [];
        for ($i = 0; $i < count($firstRecord); $i++) {
            $headers[] = 'column_' . ($i + 1);
        }

        $firstRow = [];
        foreach ($firstRecord as $index => $value) {
            $firstRow[$headers[$index]] = $value;
        }

        return [
            'header' => $headers,
            'row' => $firstRow
        ];
    }
}
