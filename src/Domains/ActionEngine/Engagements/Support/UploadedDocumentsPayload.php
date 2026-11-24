<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Support;

/**
 * Reads the files an uploading form submitted, from the `data` of a message:
 * `{typeId: {type: {id, name}, files: [{id|uuid, name}]}}`.
 */
final class UploadedDocumentsPayload
{
    /**
     * @param array<mixed> $data
     *
     * @return array<int, array{typeName: string, id: int, uuid: ?string}>
     */
    public static function files(array $data): array
    {
        $files = [];

        foreach ($data as $typeId => $document) {
            if (! is_array($document)) {
                continue;
            }

            $typeName = (string) ($document['type']['name'] ?? 'Document ' . $typeId);

            foreach ($document['files'] ?? [] as $file) {
                $uuid = isset($file['uuid']) ? (string) $file['uuid'] : null;
                $id = (int) ($file['id'] ?? 0);

                if ($id <= 0 && $uuid === null) {
                    continue;
                }

                $files[] = [
                    'typeName' => $typeName,
                    'id' => $id,
                    'uuid' => $uuid,
                ];
            }
        }

        return $files;
    }
}
