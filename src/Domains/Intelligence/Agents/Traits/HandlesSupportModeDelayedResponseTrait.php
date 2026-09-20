<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

        // The cast stays inside the coalesce: `(int) $x ?? 60` binds the cast first, which makes
        // the fallback unreachable and gives an unconfigured company a 0 minute delay.
        $delayMinutes = (int) ($channel->company->get(
            CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value
        ) ?? CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES_DEFAULT);

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

        // Re-armed per dispatch, same as the inbound burst: a later message on this channel
        // overwrites the token and the earlier job finds itself stale. The job's `is_un_response`
        // guard cannot do this on its own — it only closes once the winning turn's model call has
        // returned, so two turns queued seconds apart both pass it and both reply.
        $token = Str::uuid()->toString();

        // Floored: the token has to outlive the delay it guards, and a company configured to 0
        // would otherwise disarm it before the job it was armed for ever ran.
        Cache::put(
            SendUnrespondedAgentMessageJob::cacheKey($channel->getId()),
            $token,
            now()->addMinutes(max($delayMinutes, 1) * 2 + 5)
        );

        SendUnrespondedAgentMessageJob::dispatch(
            $channel,
            $message,
            $agentModel,
            $app,
            $params,
            $actionClass,
            $session,
            $token
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
