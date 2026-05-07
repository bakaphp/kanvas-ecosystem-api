<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;

class EventSearchClient extends BaseClient
{
    public function searchEvents(int $entityId, string $category = 'Sales'): mixed
    {
        $body = json_encode([
            //'vendor' => config('dealersocket.vendor_name'),
            //'dealerId' => config('dealersocket.dealer_id'),
            'dealerId' => $this->company->get(CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value)[CustomFieldEnum::DEALER_SOCKET_DEALER_ID->value],
            'vendor' => $this->company->get(CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value)[CustomFieldEnum::DEALER_SOCKET_VENDOR_NAME->value],
            'entityId' => $entityId,
            'eventCategory' => $category,
        ]);

        $headers = [
            'Authentication' => $this->authService->createSignature($body),
            'Content-Type' => 'application/json',
        ];

        $response = Http::withHeaders($headers)
            ->post('https://iapi.dealersocket.com/webapi/EventSearch', json_decode($body, true));

        return $response->json();
    }
}
