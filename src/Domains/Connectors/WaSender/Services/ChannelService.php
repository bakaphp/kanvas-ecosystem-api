<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;

class ChannelService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Send a text message to a WhatsApp channel.
     *
     * @param string $channelId Channel ID (e.g., '123456789@newsletter')
     * @param string $text Text content of the message
     * @return array Response from the API
     */
    public function sendChannelMessage(string $channelId, string $text): array
    {
        // Currently, only text messages can be sent to a channel
        return $this->client->post('/api/send-message', [
            'to' => $channelId,
            'text' => $text,
        ]);
    }
}
