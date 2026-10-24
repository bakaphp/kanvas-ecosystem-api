<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Social\Messages\Models\Message;

final class TransportManager
{
    public function __construct(
        private readonly ProviderRegistry $providers
    ) {
    }

    public function supports(string $provider, ChannelTypeEnum $channel): bool
    {
        return isset($this->providers->get($provider)::transports()[$channel->value]);
    }

    public function for(string $provider, ChannelTypeEnum $channel): Transport
    {
        return app($this->providers->get($provider)::transports()[$channel->value]);
    }

    public function conversationOf(Message $message): ?ConversationRef
    {
        foreach ($this->providers->all() as $provider) {
            foreach (array_unique($provider::transports()) as $transport) {
                $conversation = app($transport)->conversationOf($message);

                if ($conversation !== null) {
                    return $conversation;
                }
            }
        }

        return null;
    }
}
