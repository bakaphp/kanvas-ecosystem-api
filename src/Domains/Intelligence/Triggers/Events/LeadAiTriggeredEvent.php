<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Something happened that can change how the AI works a lead (it is new, a person took over, it
 * was handed off, it closed). `TriggersEnum` says what.
 */
final class LeadAiTriggeredEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lead $lead,
        public readonly int $triggerType
    ) {
    }

    /**
     * The one way to raise a trigger: the AI mode listens to the event, and a rule a tenant put on
     * `trigger-ai` still gets the workflow event with the same trigger type.
     */
    public static function announce(Lead $lead, int $triggerType): void
    {
        self::dispatch($lead, $triggerType);
        self::fireRule($lead, $triggerType);
    }

    /**
     * For a lead that was also announced with LeadCreatedEvent. When an agent reaches out to the
     * company's new leads, that reach-out gives the lead its AI mode right before reading it, so
     * the mode is not applied a second time from here. A lead nobody reaches out to (and one an
     * import announces with `announce()`) gets it from the event.
     */
    public static function announceNewLead(Lead $lead): void
    {
        $triggerType = TriggersEnum::NEW_LEAD->value;

        if (Agent::forNewLeads($lead->app, $lead->company) === null) {
            self::dispatch($lead, $triggerType);
        }

        self::fireRule($lead, $triggerType);
    }

    private static function fireRule(Lead $lead, int $triggerType): void
    {
        $lead->fireWorkflow(
            WorkflowEnum::TRIGGER_AI->value,
            true,
            ['app' => $lead->app, 'company' => $lead->company, 'trigger_type' => $triggerType]
        );
    }
}
