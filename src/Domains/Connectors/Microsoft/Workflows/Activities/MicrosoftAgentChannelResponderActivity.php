<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Microsoft\Actions\MicrosoftAgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\HandlesSupportModeDelayedResponseTrait;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class MicrosoftAgentChannelResponderActivity extends KanvasActivity
{
    use HandlesSupportModeDelayedResponseTrait;

    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        $defaultAgentId = $params['agent_id'] ?? null;
        $allowedChannels = $params['channelId'] ?? [];
        $channelAgentMapping = $params['channelAgentMapping'] ?? [];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::MICROSOFT,
            additionalParams: $params,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $defaultAgentId, $allowedChannels, $channelAgentMapping, $params) {
                if (empty($message)) {
                    return [
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ];
                }

                $chatJid = $message->message['chat_jid'] ?? null;
                $lead = $message->entity();
                $message->addTag('engagement');

                if ($message->message['from_me'] ?? false) {
                    return [
                        'message' => 'Message is from the owner of the account',
                        'entity' => null,
                    ];
                }

                if ($lead instanceof Lead && $lead->isAiMuted()) {
                    return [
                        'message' => 'Lead turned off AI agent responses',
                        'entity' => null,
                    ];
                }

                $agentId = $defaultAgentId;
                if (isset($channelAgentMapping[$chatJid]) && isset($channelAgentMapping[$chatJid]['agent_id'])) {
                    $agentId = $channelAgentMapping[$chatJid]['agent_id'];
                }

                if ($agentId === null) {
                    return [
                        'message' => 'No agent ID found for this channel',
                        'entity' => null,
                    ];
                }

                $chatSession = null;
                if (! $message->message['from_me']) {
                    $chatSession = new CreateSessionAction(
                        Session::from([
                            'app' => $app,
                            'company' => $channel->company,
                            'channel' => $channel,
                            'entity_namespace' => is_object($lead) ? get_class($lead) : null,
                            'entity_id' => $lead->getId(),
                            'canal_id' => $message->message['chat_jid'],
                            'user' => [
                                'name' => $lead->people->getName(),
                                'id' => $lead->people->getId(),
                                'email' => $lead->people->getEmails()->first()?->value,
                            ],
                            'agent' => Agent::getById($agentId, $app),
                        ])
                    )->execute();
                }

                if ($lead instanceof Lead) {
                    $delayedResponse = $this->handleSupportModeDelayedResponse(
                        $lead,
                        $channel,
                        $message,
                        $app,
                        $defaultAgentId,
                        $channelAgentMapping,
                        $chatJid,
                        $params,
                        MicrosoftAgentChannelResponderAction::class,
                        $chatSession
                    );

                    if ($delayedResponse !== null) {
                        return $delayedResponse;
                    }
                }

                $slowDownApiResponseTime = $params['slowDownApiResponseTime'] ?? 5;
                sleep($slowDownApiResponseTime);

                return new MicrosoftAgentChannelResponderAction(
                    $channel,
                    $message,
                    Agent::getById($agentId, $app),
                    $chatSession
                )->execute($params);
            },
            company: $channel->company,
        );
    }
}
