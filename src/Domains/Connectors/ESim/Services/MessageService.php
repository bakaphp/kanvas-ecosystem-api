<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Kanvas\Connectors\ESim\Client;
use Kanvas\Social\Messages\Models\Message;

class MessageService
{
    protected Client $client;

    public function __construct(
        protected Message $message
    ) {
        $this->client = new Client($message->app, $message->company);
    }

    public function getEsimDataInfo(): array
    {
        $messageData = $this->message->message;
        $esimBundle = $messageData['data']['plan'];
        $iccid = $messageData['data']['iccid'];

        return $this->client->get('/api/v1/esimgo/check/status/' . $iccid . '/' . $esimBundle);
    }
}
