<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\AIAssistAgentResponderAction;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'SalesAssist AI Assist Channel Responder',
    description: 'Has the SalesAssist AI assistant answer on a channel it owns. Specific to the SalesAssist '
        . 'product surface; for a normal Kanvas channel use the Internal Agent Channel Responder.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'message' => 'Supplied by the trigger — the message that arrived. Not something you set.',
    ],
)]
class AIAssistChannelResponderActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($channel, $app, $message, $params) {
                if (! $message instanceof Message) {
                    return [
                        'message' => 'Message not found',
                        'entity' => null,
                    ];
                }

                $channelType = $channel->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value);
                if ($channelType !== 'AI_ASSIST') {
                    return [
                        'message' => 'Channel is not an AI Assist channel',
                        'entity' => null,
                    ];
                }

                $messageData = $message->message;
                $fromHumanAgent = (bool) ($messageData['from_human'] ?? false);

                if (! $fromHumanAgent) {
                    return [
                        'message' => 'Message is not from a human agent',
                        'entity' => null,
                    ];
                }

                $session = Session::where('channel_id', $channel->getId())
                    ->where('is_deleted', 0)
                    ->fromApp($app)
                    ->first();

                if (! $session) {
                    return [
                        'message' => 'No active session found for AI Assist channel',
                        'entity' => null,
                    ];
                }

                $agent = $session->agent;
                if (! $agent) {
                    return [
                        'message' => 'No agent configured for AI Assist session',
                        'entity' => null,
                    ];
                }

                return new AIAssistAgentResponderAction(
                    $channel,
                    $message,
                    $agent,
                    $session
                )->execute($params);
            },
            company: $channel->company,
        );
    }
}
