<?php

declare(strict_types=1);

namespace Kanvas\Imports\Validations;

use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Imports\RemoteFiles\RemoteFileClientFactory;

class ImportConnectionValidation
{
    public static function assertValid(ImportConnectionData $data): void
    {
        RemoteFileClientFactory::assertConnectable($data->host, $data->resolvedPort());
        ImportSchedule::assertValid($data->defaultSchedule, $data->timezone);
    }
}
