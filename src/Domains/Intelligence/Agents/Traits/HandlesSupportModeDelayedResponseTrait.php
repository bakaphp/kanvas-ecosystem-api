<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Jobs\SendUnrespondedAgentMessageJob;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Support\UnrespondedLeadAgentMessageCache;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

trait HandlesSupportModeDelayedResponseTrait
{
    protected function handleSupportModeDelayedResponse(
        Lead $lead,
        Channel $channel,
        Message $message,
        Apps $app,
        ?int $defaultAgentId,
        array $channelAgentMapping,
        ?string $chatJid,
        array $params,
        string $actionClass,
        ?Session $session = null
    ): ?array {
        $isWithinWorkingHours = $lead->company->isWithinWorkingHours(now());
        $hasHumanMessage = $channel->messages()
            ->where('message->from_human', true)
            ->exists();
        if ($lead->get('ai_mode') !== IntelligenceModeEnum::SUPPORT->value || ! $isWithinWorkingHours) {
            if (! $hasHumanMessage) {
                return null;
            }
        }

        if (UnrespondedLeadAgentMessageCache::exists($lead, $channel)) {
            return [
                'message' => 'There is already an unresponded message pending in this channel',
                'entity' => $lead,
            ];
        }

        $delayMinutes = (int) $channel->company->get(
            CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value
        ) ?? 60;

        UnrespondedLeadAgentMessageCache::set($lead, $channel, $message, $delayMinutes + 5);

        $agentIdForDispatch = $defaultAgentId;
        if (isset($channelAgentMapping[$chatJid]) && isset($channelAgentMapping[$chatJid]['agent_id'])) {
            $agentIdForDispatch = $channelAgentMapping[$chatJid]['agent_id'];
        }

        if ($agentIdForDispatch === null) {
            UnrespondedLeadAgentMessageCache::clear($lead, $channel);

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
            $actionClass,
            $session
        )->delay(now()->addMinutes($delayMinutes));

        return [
            'message' => "Unresponded agent message job dispatched with {$delayMinutes} minutes delay",
            'entity' => $lead,
            'delay_minutes' => $delayMinutes,
        ];
    }
}
