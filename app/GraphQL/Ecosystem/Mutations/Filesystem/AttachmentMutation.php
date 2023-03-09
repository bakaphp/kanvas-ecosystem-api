<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Filesystem;

use Kanvas\CustomFields\DataTransferObject\CustomFieldInput;
use Kanvas\CustomFields\Repositories\CustomFieldsRepository;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemAttachInput;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;

class AttachmentMutation
{
    /**
     * Assign a filesystem to a entity.
     *
     * @param mixed $rootValue
     * @param array $request
     * @return string
     */
    public function attachFile(mixed $rootValue, array $request): string
    {
        $filesystemAttachmentInput = FilesystemAttachInput::viaRequest($request['input']);
        $customFieldInput = new CustomFieldInput(
            'filesystem',
            $filesystemAttachmentInput->systemModuleUuid,
            $filesystemAttachmentInput->entityId
        );

        $entity = CustomFieldsRepository::getEntityFromInput($customFieldInput, auth()->user());

        $attachFile = new AttachFilesystemAction(
            Filesystem::getByUuid($filesystemAttachmentInput->filesystemUuid),
            $entity
        );

        $fileSystemEntity = $attachFile->execute($filesystemAttachmentInput->fieldName);

        return (string) $fileSystemEntity->uuid;
    }

    /**
     * deAttach a file from filesystem
     *
     * @param mixed $rootValue
     * @param array $request
     * @return bool
     */
    public function deAttachFile(mixed $rootValue, array $request): bool
    {
        $fileEntity = FilesystemEntities::where('uuid', $request['uuid'])
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->notDeleted()
            ->firstOrFail();

        return $fileEntity->softDelete();
    }
}
