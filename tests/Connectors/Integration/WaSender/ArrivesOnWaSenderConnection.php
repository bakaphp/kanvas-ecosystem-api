<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Connectors\WaSender\WhatsAppTransport;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;

/**
 * An inbound WhatsApp message as the webhook files it once the number is a connection: stamped
 * with the connection its answer leaves through, with WaSender's API stood in for.
 */
trait ArrivesOnWaSenderConnection
{
    use BuildsCommunicationFixtures;

    protected function arrivedOnConnection(Message $inbound, Agent $agent): Message
    {
        $connection = $this->connection('wasender', '+18095550100', ['config' => ['agent_id' => $agent->getId()]]);

        $inbound->message = [...$inbound->message, 'communication' => [
            'connection_id' => $connection->getId(),
            'channel' => 'whatsapp',
            'thread' => str_replace('@s.whatsapp.net', '', (string) $inbound->message['chat_jid']),
            'activation_version' => $connection->activation_version,
        ]];
        $inbound->saveQuietly();

        $service = Mockery::mock(MessageService::class);
        $service->shouldReceive('sendTextMessage')->andReturn(['data' => ['msgId' => 1]]);

        $this->app->bind(WhatsAppTransport::class, fn () => new class ($service) extends WhatsAppTransport {
            public function __construct(private readonly MessageService $service)
            {
            }

            protected function messageService(Delivery $delivery): MessageService
            {
                return $this->service;
            }
        });

        return $inbound->fresh();
    }
}
