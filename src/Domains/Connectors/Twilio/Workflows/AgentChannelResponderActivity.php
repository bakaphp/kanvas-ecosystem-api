<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Workflows;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Jobs\SendUnrespondedAgentMessageJob;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
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
            integration: IntegrationsEnum::TWILIO,
            additionalParams: $params,
            integrationOperation: function ($channel, $app, $integrationCompany, $additionalParams) use ($message, $user, $defaultAgentId, $allowedChannels, $channelAgentMapping, $params) {
                if (empty($message)) {
                    return [
                        'message' => 'Message or user not found',
                        'entity' => null,
                    ];
                }

                $chatJid = $message->message['chat_jid'] ?? null;
                $filterByChannel = (bool) ($params['filterByChannel'] ?? false);
                // Check if this channel is allowed
                if ($filterByChannel && ! in_array($chatJid, $allowedChannels)) {
                    return [
                        'message' => 'Agent is not running on this channel',
                        'entity' => null,
                    ];
                }
                $lead = $message->entity();
                $message->addTag('engagement');

                // Don't process messages from the phone owner
                if ($message->message['from_me'] ?? false) {
                    return [
                        'message' => 'Message is from the owner of the phone tied to the agent',
                        'entity' => null,
                    ];
                }

                if ($lead instanceof Lead && $lead->isAiMuted()) {
                    return [
                        'message' => 'Lead turned off AI agent responses',
                        'entity' => null,
                    ];
                }

                $isWithinWorkingHours = $lead->company->isWithinWorkingHours(now());

                if ($lead instanceof Lead && $lead->get('ai_mode') === IntelligenceModeEnum::SUPPORT->value && $isWithinWorkingHours) {
                    $cacheKey = "unresponded_agent_message:{$lead->getId()}:{$channel->getId()}";

                    if (Cache::has($cacheKey)) {
                        return [
                            'message' => 'There is already an unresponded message pending in this channel',
                            'entity' => $lead,
                        ];
                    }

                    $delayMinutes = (int) $channel->company->get(
                        CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value
                    ) ?? 60;

                    Cache::put($cacheKey, [
                        'message_id' => $message->getId(),
                        'channel_id' => $channel->getId(),
                        'lead_id' => $lead->getId(),
                        'dispatched_at' => now()->toIso8601String(),
                    ], now()->addMinutes($delayMinutes + 5));

                    $agentIdForDispatch = $defaultAgentId;
                    if (isset($channelAgentMapping[$chatJid]) && isset($channelAgentMapping[$chatJid]['agent_id'])) {
                        $agentIdForDispatch = $channelAgentMapping[$chatJid]['agent_id'];
                    }

                    if ($agentIdForDispatch === null) {
                        Cache::forget($cacheKey);
                        return [
                            'message' => 'No agent ID found for this channel',
                            'entity' => null,
                        ];
                    }

                    SendUnrespondedAgentMessageJob::dispatch(
                        $channel,
                        $message,
                        Agent::getById($agentIdForDispatch, $app),
                        $app,
                        $params,
                        AgentChannelResponderAction::class
                    )->delay(now()->addMinutes($delayMinutes));

                    return [
                        'message' => "Unresponded agent message job dispatched with {$delayMinutes} minutes delay",
                        'entity' => $lead,
                        'delay_minutes' => $delayMinutes,
                    ];
                }
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

                $chatSession = null;
                if (! $message->message['from_me']) {
                    $chatSession = new CreateSessionAction(
                        Session::from([
                            'app' => $app,
                            'company' => $channel->company,
                            'channel' => $channel,
                            'entity_namespace' => is_object($message->entity()) ? get_class($message->entity()) : null,
                            'entity_id' => $message->entity()->getId(),
                            'canal_id' => $message->message['chat_jid'],
                            'user' => [
                                'name' => $message->entity()->people->getName(),
                                'id' => $message->entity()->people->getId(),
                                'email' => $message->entity()->people->getEmails()->first()?->value,
                            ],
                            'agent' => Agent::getById($agentId, $app),
                        ])
                    )->execute();
                }

                return new AgentChannelResponderAction(
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
