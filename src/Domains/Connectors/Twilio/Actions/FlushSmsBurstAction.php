<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Social\Messages\Support\BurstHandler;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

/**
 * An SMS burst has gone quiet: announce it once, with the whole flurry as the turn's text.
 *
 * The agent pass itself stays in the workflow rather than here — SMS routes through
 * AgentChannelResponderActivity, which also owns the AI-mode guards and the support-mode handoff.
 * This only decides *when* that fires, and hands it the burst instead of a single message.
 */
class FlushSmsBurstAction extends BurstHandler
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
                'communication_channel' => 'sms',
                'burst_text' => $prompt,
                'burst_message_ids' => $this->messageIds(),
            ]
        );

        return [
            'message' => $prompt,
            'burst_message_ids' => $this->messageIds(),
        ];
    }
}
