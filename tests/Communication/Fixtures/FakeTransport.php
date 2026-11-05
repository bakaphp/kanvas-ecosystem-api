<?php

declare(strict_types=1);

namespace Tests\Communication\Fixtures;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

final class FakeTransport implements Transport
{
    /** @var list<array{delivery_id: int, to: string|null, text: string}> */
    public array $sent = [];

    public ?TransportResult $sendResult = null;
    public ?Throwable $sendException = null;
    public ?TransportResult $lookupResult = null;

    public function send(Delivery $delivery, Content $content): TransportResult
    {
        $this->sent[] = [
            'delivery_id' => $delivery->getId(),
            'to' => $delivery->to_address,
            'text' => $content->text,
        ];

        if ($this->sendException !== null) {
            throw $this->sendException;
        }

        return $this->sendResult ?? TransportResult::accepted('FAKE-' . count($this->sent));
    }

    public function lookup(Delivery $delivery): ?TransportResult
    {
        return $this->lookupResult;
    }

    public function conversation(Connection $connection, string $address): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::SMS,
            provider: FakeProvider::slug(),
            accountKey: $connection->account_key,
            thread: $address,
            channelSlug: 'fake-' . preg_replace('/\D/', '', $address),
            messageVerb: 'fake-sms'
        );
    }

    public function conversationOf(Message $message): ?ConversationRef
    {
        return null;
    }
}
