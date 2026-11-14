<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Override;

class ProcessConversationOpenedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        $identifier = $this->contactIdentifier();

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        return [
            'status' => 'conversation_opened',
            'channel_id' => $this->resolveChannel()?->getId(),
            'identifier' => $identifier,
            'source' => $this->payload['conversation']['source'] ?? null,
        ];
    }
}
