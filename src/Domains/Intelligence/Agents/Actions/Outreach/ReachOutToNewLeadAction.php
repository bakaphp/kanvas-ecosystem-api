<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Outreach;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Triggers\Actions\TriggerLeadIntelligenceAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Throwable;

/**
 * A new lead's first message from the agent that has reach-out switched on. The rule that runs it
 * (ReachOutToNewLeadActivity) is only a trigger: the agent's own configuration is the switch, and its
 * lead sources are the only limit.
 */
final class ReachOutToNewLeadAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly Agent $agent
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        // The AI mode first: the reach-out reads the mode the new lead was just given.
        new TriggerLeadIntelligenceAction($this->lead, TriggersEnum::NEW_LEAD->value)->execute();

        if (! new LeadConfigurationService()->isFirstMessageEnabled($this->lead)) {
            $this->lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $this->lead->set(AgentReachOutConfigEnum::REASON->value, 'first_message_disabled');

            return ['message' => 'First message disabled by the lead type', 'status' => 'skipped'];
        }

        $result = new AgentReachOutAction(
            $this->lead,
            ['lead_source_allowlist' => $this->agent->reachOutLeadSources()],
            deferDelivery: self::defersDelivery($this->lead),
            agent: $this->agent,
        )->execute();

        AgentReachOutAction::announceReachOut($this->lead, $this->agent, $result);

        return $result;
    }

    /**
     * Support mode holds the first message for a person to pick up, which only makes sense while
     * someone is at work: after hours it goes out. Hours that cannot be read keep the hold.
     */
    public static function defersDelivery(Lead $lead): bool
    {
        if (! $lead->isAiSupport()) {
            return false;
        }

        try {
            $hours = new CompanyWorkHoursTool($lead)->execute();

            return ($hours['status'] ?? null) !== 'after_hours';
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }
}
