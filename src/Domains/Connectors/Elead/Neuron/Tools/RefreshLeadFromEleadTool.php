<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Neuron\Tools;

use Kanvas\Connectors\Elead\Actions\PullAppointmentsAction;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Entities\Lead as EleadOpportunity;
use Kanvas\Connectors\Elead\Neuron\Tools\Concerns\ReadsEleadForLead;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Refresh Lead From eLead', category: 'elead')]
class RefreshLeadFromEleadTool extends Tool
{
    use GuardsRepeatCalls;
    use ReadsEleadForLead;
    use TrackByInputs;

    protected string $name = 'refresh_lead_from_elead';

    protected ?string $description = 'Pull the lead\'s current state from eLead (the dealer CRM) into Kanvas now, instead of '
        . 'waiting for the next sync: open/closed status, owner (the primary salesperson), vehicle of interest, '
        . 'trade-in, showroom state, and its open appointments as Kanvas calendar events. Use it when someone says '
        . 'eLead was updated and Kanvas does not show it yet. eLead is the source of truth here: Kanvas is '
        . 'updated from it, never the other way round. Call it once; the answer is the refreshed lead.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead to refresh from eLead.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id): array
    {
        return $this->oncePerTurn(
            ['lead_id' => $lead_id],
            fn (): array => $this->refresh($lead_id),
            note: 'You already refreshed this lead from eLead in this turn; this is that same result. '
                . 'Use it — do NOT call this again.',
            rememberFailures: false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(int $leadId): array
    {
        $refusal = $this->customerSurfaceRefusal('Refreshing a lead from eLead');
        if ($refusal !== null) {
            return $refusal;
        }

        $lead = $this->resolveEleadLeadOrError($leadId);
        if (is_array($lead)) {
            return $lead;
        }

        try {
            $opportunity = $this->syncLead($lead);
            $events = $this->pullAppointments($lead);
        } catch (Throwable $e) {
            return $this->eleadFailure($e, $lead);
        }

        $lead->unsetRelations();
        $timezone = $this->timezone($lead);

        return $this->ok(
            [
                'lead_id' => $lead->getId(),
                'elead_opportunity_id' => $opportunity->id,
                'elead_status' => $opportunity->status,
                'elead_sub_status' => $opportunity->subStatus,
                'status' => $lead->statusName(),
                'is_open' => $lead->hasOpenLeadStatus(),
                'owner' => $lead->owner?->fullName(),
                'in_showroom' => $opportunity->inShowRoom(),
                'vehicle_interest' => $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value),
                'trade_in' => $lead->get(LeadCustomFieldEnum::TRADE_IN->value),
                'appointments_synced' => count($events),
                'upcoming_appointments' => array_map(fn (Event $event): array => [
                    'kanvas_event_id' => $event->getId(),
                    'name' => $event->name,
                    'start_at' => $event->latestVersion()?->start_at?->copy()->setTimezone($timezone)->toIso8601String(),
                ], array_values($events)),
            ],
            guidance: 'Kanvas now matches eLead for this lead; get_lead_ref reflects it.'
        );
    }

    protected function syncLead(Lead $lead): EleadOpportunity
    {
        return new SyncLeadAction($lead, fresh: true)->execute();
    }

    /**
     * @return array<string, Event>
     */
    protected function pullAppointments(Lead $lead): array
    {
        return new PullAppointmentsAction($lead)->execute();
    }
}
