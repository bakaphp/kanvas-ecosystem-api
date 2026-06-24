<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Carbon\Carbon;
use Kanvas\Connectors\Reynolds\Client;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Ramsey\Uuid\Uuid;

class ApplicationAreaBuilder
{
    public static function build(
        Client $client,
        TransactionCodeEnum $task,
        string $transType,
        string $destinationCode = 'RRCRM'
    ): array {
        return [
            'BODId' => Uuid::uuid4()->toString(),
            'CreationDateTime' => Carbon::now()->format('Y-m-d\TH:i:s'),
            'Sender' => [
                'Component' => 'SalesAssistCRM',
                'Task' => $task->value,
                'TransType' => $transType,
                'SenderName' => $client->getSenderName(),
            ],
            'Destination' => [
                'DestinationNameCode' => $destinationCode,
                'DealerNumber' => $client->getDealerNumber(),
                'StoreNumber' => $client->getStoreNumber(),
                'AreaNumber' => $client->getAreaNumber(),
                'BusinessUnitName' => $client->getBusinessUnitName(),
            ],
        ];
    }
}
