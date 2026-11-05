<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Social\Messages\Support\BurstHandler;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

/**
 * A burst has gone quiet: announce it once, with the whole flurry as the turn's text.
 *
 * The agent pass stays in the workflow rather than here — the conversation responder owns the
 * AI-mode guards and the support-mode handoff. This only decides *when* it fires, and hands it the
 * burst instead of a single message.
 */
class FlushInboundBurstAction extends BurstHandler
{
    #[Override]
    public function execute(array $params = []): array
    {
        $head = $this->head();
        $prompt = $this->prompt();

        $this->channel->fireWorkflow(
            WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            true,
            [
                'message' => $head,
                'user' => $head->user,
                'app' => $head->app,
                'company' => $head->company,
                'text' => $prompt,
                'communication_channel' => $params['communication_channel'] ?? $this->medium(),
                'burst_text' => $prompt,
                'burst_message_ids' => $this->messageIds(),
            ]
        );

        return [
            'message' => $prompt,
            'burst_message_ids' => $this->messageIds(),
        ];
    }

    /**
     * For a flush queued without the medium in its params.
     */
    protected function medium(): ?string
    {
        return $this->head()->message['communication']['channel'] ?? null;
    }
}
