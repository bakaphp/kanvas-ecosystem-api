<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\InternalAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Internal kernel-based channel responder: bind a Rule on Channel + `after-adding-message-to-channel`
 * to get an agent reply on any non-connector channel. Routes through AgentChatKernel (Neuron/Laravel/
 * ADK all work). Rule is_async = 0 runs it inline (blocking).
 */
#[WorkflowAction(
    name: 'Internal Agent Channel Responder',
    description: 'Has an agent read a message on an INTERNAL Kanvas channel and reply back on that same '
        . 'channel. Use it for channels that are not a connector — internal chat, an app\'s own '
        . 'conversation. Do NOT use it when the agent should read and act somewhere else; use Run Agent '
        . 'On Record for that.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'agent_id' => 'Which agent answers. Also read from the channel slug or metadata when omitted.',
        'message' => 'Supplied by the trigger — the message that arrived. Not something you set.',
        'filterByChannel' => 'true to answer only on the chat ids listed in channelId. Default false, meaning '
            . 'every channel matched by the rule is answered.',
        'channelId' => 'Chat ids the agent may answer on, used when filterByChannel is true.',
        'channelAgentMapping' => 'Map of chat id to agent id, when different channels need different agents.',
    ],
)]
class MessageAgentChannelResponderActivity extends KanvasActivity
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

                try {
                    $reply = new InternalAgentChannelResponderAction(
                        $agent,
                        $message,
                        $entity,
                    )->execute();
                } catch (ValidationException $e) {
                    return $this->failWorkflow([
                        'message' => $e->getMessage(),
                        'entity' => null,
                    ]);
                }

                return [
                    'message' => 'Agent reply created',
                    'entity' => $reply,
                ];
            },
            company: $company,
        );
    }
}
