<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemAttachInput;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\SystemModules\DataTransferObject\SystemModuleEntityInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

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
        $fileEntity = FilesystemEntities::where('uuid', $request['uuid'])
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->notDeleted()
            ->firstOrFail();

        return $fileEntity->softDelete();
    }

    public function deAttachFiles(mixed $rootValue, array $request): bool
    {
        $company = auth()->user()->getCurrentCompany();
        $fileEntities = FilesystemEntities::whereIn('uuid', $request['uuids'])
            ->fromCompany($company)
            ->notDeleted()
            ->get();

        foreach ($fileEntities as $fileEntity) {
            $fileEntity->softDelete();
        }

        return true;
    }

    /**
     * Upload a file, store it on the server and return the path.
     */
    public function singleFile(mixed $rootValue, array $request): Filesystem
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request['file'];
        $filesystem = new FilesystemServices(app(Apps::class));

        return $filesystem->upload($file, auth()->user());
    }

    /**
     * Multiple Upload a file, store it on the server and return the path.
     */
    public function multiFile(mixed $rootValue, array $request): array
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $files = $request['files'];
        $fileSystems = [];

        foreach ($files as $file) {
            $uploadFile = new FilesystemServices(app(Apps::class));

            $fileSystems[] = $uploadFile->upload($file, auth()->user());
        }

        return $fileSystems;
    }
}
