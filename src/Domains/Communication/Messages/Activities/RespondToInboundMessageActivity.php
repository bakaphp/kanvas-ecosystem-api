<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Communication Answer Inbound Message',
    description: 'The agent that owns the number or mailbox an inbound message arrived on answers it. '
        . 'A message on a connection no agent owns is skipped (runs on after-adding-message-to-channel, param message)',
    requiredParams: ['message'],
)]
class RespondToInboundMessageActivity extends KanvasActivity
{
    // A retry after the send would message the customer twice.
    public $tries = 1;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        if (! $entity instanceof Channel || ! $message instanceof Message) {
            return $this->skipWorkflow('not_a_message');
        }

        // The ids travel with the text: the agent leaves those rows out of its loaded history, or a
        // burst reaches the model twice (once filed, once as the prompt).
        $burst = array_filter([
            'burst_text' => $params['burst_text'] ?? null,
            'burst_message_ids' => $params['burst_message_ids'] ?? null,
        ]);
        $result = new RespondToInboundMessageAction($entity, $message, $burst)->executeIfOwned();

        if ($result === null) {
            return $this->skipWorkflow('not_owned');
        }

        if (isset($result['reason'])) {
            return $this->skipWorkflow($result['reason'], ['detail' => $result['message'] ?? null]);
        }

        return $result;
    }
}
