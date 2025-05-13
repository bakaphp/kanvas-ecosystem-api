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
        //$fromMe = $params['from_me'] ?? null;

        $agentId = $params['agent_id'] ?? null;
        $runOnThisChannels = $params['channelId'] ?? [];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::WASENDER,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $user, $agentId, $runOnThisChannels) {
                if (empty($message)) {
                    return [
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ];
                }

                if (in_array($message->message['chat_jid'], $runOnThisChannels)) {
                    return [
                        'message' => 'Agent is not running on this channel',
                        'entity' => null,
                    ];
                }

                if ($message->message['from_me']) {
                    return [
                        'message' => 'Message is from the owner of the phone tied to the agent',
                        'entity' => null,
                    ];
                }

                return new AgentChannelResponderAction(
                    $channel,
                    $message,
                    Agent::getById($agentId, $app)
                )->execute();
            },
            company: $channel->company,
        );
    }
}
