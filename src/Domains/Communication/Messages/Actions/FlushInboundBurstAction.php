<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Social\Messages\Support\BurstHandler;
use Override;

/**
 * A burst has gone quiet: it is announced once, on its first message, with the whole flurry as the
 * turn's text.
 */
class FlushInboundBurstAction extends BurstHandler
{
    #[Override]
    public function execute(array $params = []): array
    {
        $prompt = $this->prompt();

        new AnnounceInboundTurnAction(
            $this->channel,
            $this->head(),
            $prompt,
            [
                'communication_channel' => $params['communication_channel'] ?? null,
                'burst_text' => $prompt,
                'burst_message_ids' => $this->messageIds(),
            ],
            $prompt
        )->execute();

        return [
            'message' => $prompt,
            'burst_message_ids' => $this->messageIds(),
        ];
    }
}
