<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\FollowUp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;

/**
 * Manual trigger for the v1 follow-up engine.
 *
 * Resolves the same agent the cron path uses (stage config override OR
 * AgentEnum::FOLLOW_UP_ENGAGER fallback) so the message identity is identical
 * regardless of how the follow-up was kicked off. force=true bypasses only the
 * silence-interval gate — every other gate (exhaustion, max_retries, channel
 * config, WhatsApp 24h template lockdown, agent JSON decision) still runs.
 *
 * Runs INLINE in the GraphQL request — does NOT enqueue. This is intentional
 * for v1: humans clicking "follow up now" want immediate feedback. If volume
 * becomes a problem, dispatch LeadFollowUpJob instead and return a "queued"
 * outcome shape.
 */
class FollowUpLeadMutation
{
    /**
     * @param array{leadId: string|int} $request
     * @return array{kind: string, reason: ?string, message: ?string}
     */
    public function execute(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Lead $lead */
        $lead = Lead::getByIdFromCompanyApp((int) $request['leadId'], $company, $app);

        $config = FollowUpConfig::fromStage($lead->stage);
        $agentName = $config?->agentName ?? AgentEnum::FOLLOW_UP_ENGAGER->value;

        /** @var Agent $agent */
        $agent = Agent::getByName($agentName, $app);

        $outcome = new FollowUpLeadAction(
            app: $app,
            company: $company,
            lead: $lead,
            agent: $agent,
            force: true,
        )->execute();

        return [
            'kind' => $outcome->kind->value,
            'reason' => $outcome->reason,
            'message' => $outcome->message,
        ];
    }
}
