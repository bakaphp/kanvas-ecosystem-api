<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\RuntimeAgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Runtime Agent Channel Responder',
    description: 'Has a machine-runtime agent (OpenClaw, Hermes) read a channel message and reply back on that '
        . 'channel. Only for agents whose type is a hosted/container runtime — an in-process agent will '
        . 'not respond through this. Do NOT use it for read-and-act work.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'agent_id' => 'Which runtime agent answers.',
        'message' => 'Supplied by the trigger — the message that arrived. Not something you set.',
    ],
)]
class RuntimeAgentChannelResponderActivity extends KanvasActivity
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
            integrationOperation: function () use ($entity, $app, $message, $defaultAgentId, $allowedChannels, $channelAgentMapping, $params) {
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
                $agentId ??= $app->get('openclaw_default_agent_id');

                if (empty($agentId)) {
                    return [
                        'message' => 'No agent_id resolved for this channel',
                        'entity' => null,
                    ];
                }

                /** @var Agent $agent */
                $agent = Agent::getById((int) $agentId, $app);

                $reply = new RuntimeAgentChannelResponderAction(
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
