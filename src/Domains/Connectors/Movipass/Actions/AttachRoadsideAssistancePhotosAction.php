<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class AttachRoadsideAssistancePhotosAction
{
    public function execute(Order $order, array $filesystemUuids, AppInterface $app, string $fieldName = 'assistance_case_photo'): array
    {
        $uniqueUuids = array_values(array_unique(array_filter(array_map(static function (mixed $uuid): string {
            return is_string($uuid) ? trim($uuid) : '';
        }, $filesystemUuids), static fn (string $uuid): bool => $uuid !== '')));

        $attached = [];

        foreach ($uniqueUuids as $filesystemUuid) {
            try {
                /** @var Filesystem $file */
                $file = Filesystem::getByUuid($filesystemUuid, $app);
                new AttachFilesystemAction($file, $order)->execute($fieldName);
                $attached[] = [
                    'uuid' => $file->uuid,
                    'url' => $file->url,
                    'name' => $file->name,
                    'file_type' => $file->file_type,
                ];
            } catch (Throwable $exception) {
                throw new ValidationException('Invalid roadside assistance photo uuid: ' . $filesystemUuid);
            }
        }

        return $attached;
    }
}
