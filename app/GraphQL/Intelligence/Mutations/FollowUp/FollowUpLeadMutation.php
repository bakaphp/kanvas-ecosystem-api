<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\FollowUp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;

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
        $agent = Agent::getByNameFromCompanyApp($agentName, $company, $app);

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

    /**
     * Full reset: count=0, exhausted_at/reason cleared, stage_entered_at = now.
     * Use when a lead is stuck after a bug (e.g. agent: invalid_json_response).
     *
     * @param array{leadId: string|int} $request
     */
    public function reset(mixed $rootValue, array $request): bool
    {
        $this->resolveLead($request)->resetFollowUpState();

        return true;
    }

    /**
     * Surgical reset: clears exhausted_at/reason + count + last_at, preserves
     * other state keys (like stage_entered_at). Mirrors the inbound-reply
     * re-engagement hook.
     *
     * @param array{leadId: string|int} $request
     */
    public function resume(mixed $rootValue, array $request): bool
    {
        $this->resolveLead($request)->resumeFollowUp();

        return true;
    }

    /**
     * @param array{leadId: string|int} $request
     */
    private function resolveLead(array $request): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Lead $lead */
        $lead = Lead::getByIdFromCompanyApp((int) $request['leadId'], $company, $app);

        return $lead;
    }
}
