<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AgentChannelResponderActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;
        $user = $params['user'] ?? null; //@todo fix this get the user from the message

        $defaultAgentId = $params['agent_id'] ?? null;
        $allowedChannels = $params['channelId'] ?? [];
        $channelAgentMapping = $params['channelAgentMapping'] ?? [];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::WASENDER,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $user, $defaultAgentId, $allowedChannels, $channelAgentMapping) {
                if (empty($message)) {
                    return [
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ];
                }

                $chatJid = $message->message['chat_jid'] ?? null;

                // Check if this channel is allowed
                if (! in_array($chatJid, $allowedChannels)) {
                    return [
                        'message' => 'Agent is not running on this channel',
                        'entity' => null,
                    ];
                }

                // Don't process messages from the phone owner
                if ($message->message['from_me'] ?? false) {
                    return [
                        'message' => 'Message is from the owner of the phone tied to the agent',
                        'entity' => null,
                    ];
                }

                // Get agent ID from mapping or use default
                $agentId = $defaultAgentId;
                if (isset($channelAgentMapping[$chatJid]) && isset($channelAgentMapping[$chatJid]['agent_id'])) {
                    $agentId = $channelAgentMapping[$chatJid]['agent_id'];
                }

                // Ensure we have a valid agent ID
                if ($agentId === null) {
                    return [
                        'message' => 'No agent ID found for this channel',
                        'entity' => null,
                    ];
                }

                return new AgentChannelResponderAction(
                    $channel,
                    $message,
                    Agent::getById($agentId, $app)
                )->execute($additionalParams);
            },
            company: $channel->company,
        );
    }
}
