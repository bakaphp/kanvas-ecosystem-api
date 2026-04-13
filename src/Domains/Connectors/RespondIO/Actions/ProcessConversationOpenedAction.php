<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Override;

class ProcessConversationOpenedAction extends BaseRespondIOAction
{
    use HasChannelProcessing;

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var array<string, mixed> $conversation */
        $conversation = $this->payload['conversation'] ?? [];

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $channel = $this->findChannelByIdentifier($this->app, $this->company, $identifier);

        return [
            'status' => 'conversation_opened',
            'channel_id' => $channel?->getId(),
            'identifier' => $identifier,
            'source' => $conversation['source'] ?? null,
        ];
    }
}
