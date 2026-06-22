<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\InternalAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Internal kernel-based channel responder. Bind a Rule on the Channel model + the
 * `after-adding-message-to-channel` event to this activity to get an agent reply
 * for any non-connector channel. Unlike RuntimeAgentChannelResponderActivity, this
 * routes through AgentChatKernel so Neuron / Laravel / ADK agents all work.
 *
 * Set the Rule's is_async = 0 to run it inline (blocking) so the reply is created in
 * the channel synchronously.
 */
#[WorkflowAction]
class InternalAgentChannelResponderActivity extends KanvasActivity
{
    public function execute(Channel $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;
        $defaultAgentId = $params['agent_id'] ?? null;
        $allowedChannels = $params['channelId'] ?? [];
        $channelAgentMapping = $params['channelAgentMapping'] ?? [];

        /** @var Companies $company */
        $company = $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function () use ($entity, $app, $message, $defaultAgentId, $allowedChannels, $channelAgentMapping, $params): array {
                if (! $message instanceof Message) {
                    return [
                        'message' => 'No message provided in params',
                        'entity' => null,
                    ];
                }

                $chatJid = $message->message['chat_jid'] ?? null;
                $filterByChannel = (bool) ($params['filterByChannel'] ?? false);

                if ($filterByChannel && ! in_array($chatJid, $allowedChannels)) {
                    return [
                        'message' => 'Agent is not running on this channel',
                        'entity' => null,
                    ];
                }

                if (($message->message['from_me'] ?? false) === true) {
                    return [
                        'message' => 'Message is from the agent side, skipping to avoid loop',
                        'entity' => null,
                    ];
                }

                $agentId = null;

                if (preg_match('/^channel-\d+-(\d+)-session$/', $entity->slug, $matches)) {
                    $agentId = (int) $matches[1];
                }

                $metadata = is_array($entity->metadata) ? $entity->metadata : [];
                $agentId ??= $metadata['agent_id'] ?? null;

                if ($agentId === null && $chatJid !== null && isset($channelAgentMapping[$chatJid]['agent_id'])) {
                    $agentId = $channelAgentMapping[$chatJid]['agent_id'];
                }

                $agentId ??= $defaultAgentId;

                if (empty($agentId)) {
                    return [
                        'message' => 'No agent_id resolved for this channel',
                        'entity' => null,
                    ];
                }

                /** @var Agent $agent */
                $agent = Agent::getById((int) $agentId, $app);

                $reply = new InternalAgentChannelResponderAction(
                    $agent,
                    $message,
                    $entity,
                )->execute();

                return [
                    'message' => 'Agent reply created',
                    'entity' => $reply,
                ];
            },
            company: $company,
        );
    }
}
