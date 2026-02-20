<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Throwable;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Souk\Orders\Models\Order;

class AttachRoadsideAssistancePhotosAction
{
    public function execute(Order $order, array $filesystemUuids, string $fieldName = 'assistance_case_photo'): void
    {
        $uniqueUuids = array_values(array_unique(array_filter(array_map(static function (mixed $uuid): string {
            return is_string($uuid) ? trim($uuid) : '';
        }, $filesystemUuids), static fn (string $uuid): bool => $uuid !== '')));

        foreach ($uniqueUuids as $filesystemUuid) {
            try {
                $file = Filesystem::getByUuid($filesystemUuid);
                new AttachFilesystemAction($file, $order)->execute($fieldName);
            } catch (Throwable $exception) {
                throw new ValidationException('Invalid roadside assistance photo uuid: ' . $filesystemUuid);
            }
        }
    }
}
