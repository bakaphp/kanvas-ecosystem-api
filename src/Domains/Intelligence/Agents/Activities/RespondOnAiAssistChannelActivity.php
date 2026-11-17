<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\AIAssistAgentResponderAction;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

/**
 * The agent the channel's session was opened with answers; a channel with no session or no agent
 * is not answered.
 */
#[WorkflowAction(
    name: 'Agents Respond On AI Assist Channel',
    description: 'The agent answers a teammate\'s message on a lead\'s AI Assist channel (runs on the channel '
        . 'updated event, param message, conditions from_human and the channel name)',
    requiredParams: ['message'],
)]
class RespondOnAiAssistChannelActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        // The agent's own answer lands on the same channel; only a person's message gets one.
        if (! $entity instanceof Channel
            || ! $entity->isAiAssistChannel()
            || ! $message instanceof Message
            || ! $message->isFromHuman()
        ) {
            return $this->skipWorkflow('Not a person\'s message on an AI Assist channel');
        }

        if ($entity->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value) !== 'AI_ASSIST') {
            return $this->skipWorkflow('The channel is not an agent\'s AI Assist conversation');
        }

        $session = Session::query()
            ->fromApp($entity->app)
            ->where('channel_id', $entity->getId())
            ->notDeleted()
            ->first();

        if ($session?->agent === null) {
            return $this->skipWorkflow('No agent session on the channel');
        }

        try {
            return new AIAssistAgentResponderAction(
                $entity,
                $message,
                $session->agent,
                $session
            )->execute();
        } catch (ValidationException|AgentReplySkippedException $skipped) {
            // Nothing to answer (no lead on the message, an empty reply): not a fault.
            return $this->skipWorkflow($skipped->getMessage());
        }
    }
}
