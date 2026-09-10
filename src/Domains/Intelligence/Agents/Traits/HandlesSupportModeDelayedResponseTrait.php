<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Jobs\SendUnrespondedAgentMessageJob;
use Kanvas\Intelligence\Sessions\Models\Session;
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
        if (! $this->isSupportModeDelayedResponseEnabled($app)) {
            return null;
        }

        $isWithinWorkingHours = $lead->company->isWithinWorkingHours(now());
        $hasHumanMessage = $channel->messages()
            ->where('message->from_human', true)
            ->exists();

        if (! $isWithinWorkingHours && ! $hasHumanMessage) {
            return null;
        }

        if (! $lead->isAiSupport()) {
            return null;
        }

        $delayMinutes = (int) $channel->company->get(
            CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value
        ) ?? 60;

        $agentIdForDispatch = $defaultAgentId;
        if (isset($channelAgentMapping[$chatJid]) && isset($channelAgentMapping[$chatJid]['agent_id'])) {
            $agentIdForDispatch = $channelAgentMapping[$chatJid]['agent_id'];
        }

        if ($agentIdForDispatch === null) {
            return [
                'message' => 'No agent ID found for this channel',
                'entity' => null,
            ];
        }

        $agentModel = Agent::getById($agentIdForDispatch, $app);

        SendUnrespondedAgentMessageJob::dispatch(
            $channel,
            $message,
            $agentModel,
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

    protected function isSupportModeDelayedResponseEnabled(Apps $app): bool
    {
        $setting = $app->get(ConfigurationEnum::SUPPORT_MODE_DELAYED_RESPONSE->value);

        if ($setting === null) {
            return true;
        }

        return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }
}
