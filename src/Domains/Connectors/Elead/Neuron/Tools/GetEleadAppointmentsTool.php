<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Neuron\Tools;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Elead\Actions\PullAppointmentsAction;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Neuron\Tools\Concerns\ReadsEleadForLead;
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

#[AgentTool(name: 'Get eLead Appointments', category: 'elead')]
class GetEleadAppointmentsTool extends Tool
{
    use GuardsRepeatCalls;
    use ReadsEleadForLead;
    use TrackByInputs;

    protected string $name = 'get_elead_appointments';

    protected ?string $description = 'Read the lead\'s OPEN appointments straight from eLead (the dealer CRM) for the next 31 days, '
        . 'and put them on the lead as Kanvas calendar events. Use it when someone asks whether an appointment is '
        . 'set in eLead, or when get_lead_ref shows no upcoming appointment but one is expected: an appointment '
        . 'booked in eLead reaches Kanvas only on the next sync, so this is the live answer. Completed and '
        . 'cancelled appointments are not here; read_elead_activity_history has them.';

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
                description: 'The ID of the lead whose eLead appointments to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id): array
    {
        return $this->oncePerTurn(['lead_id' => $lead_id], fn (): array => $this->read($lead_id));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $leadId): array
    {
        $lead = $this->resolveEleadLeadOrError($leadId);
        if (is_array($lead)) {
            return $lead;
        }

        $from = Carbon::now('UTC');
        $to = $from->copy()->addDays(PullAppointmentsAction::WINDOW_DAYS);

        try {
            $activities = $this->fetchActivities(
                $lead,
                $from,
                $to,
                openOnly: true
            );
        } catch (Throwable $e) {
            return $this->eleadFailure($e, $lead);
        }

        $typeNames = ConfigurationEnum::appointmentActivityTypes($lead->company);
        $appointments = array_values(array_filter(
            $activities,
            fn (SalesActivities $activity): bool => $activity->id !== null
                && $activity->dueDate() !== null
                && $activity->isActivityType($typeNames)
        ));
        usort($appointments, fn (SalesActivities $a, SalesActivities $b): int => $a->dueDate() <=> $b->dueDate());

        $events = $this->mirror($lead, $activities, $from, $to);
        $timezone = $this->timezone($lead);

        $payload = [
            'lead_id' => $lead->getId(),
            'elead_opportunity_id' => $this->opportunityId($lead),
            'window' => [
                'from' => $from->copy()->setTimezone($timezone)->toIso8601String(),
                'to' => $to->copy()->setTimezone($timezone)->toIso8601String(),
            ],
            'count' => count($appointments),
            'appointments' => array_map(fn (SalesActivities $activity): array => [
                ...$this->presentActivity($activity, $timezone),
                'kanvas_event_id' => ($events[(string) $activity->id] ?? null)?->getId(),
            ], $appointments),
        ];

        if ($appointments === []) {
            return $this->noop(
                $payload,
                guidance: 'eLead has no open appointment for this lead in the next 31 days. Say so plainly; '
                    . 'read_elead_activity_history shows appointments that were already completed or closed.'
            );
        }

        return $this->ok(
            $payload,
            guidance: 'These appointments are now on the lead in Kanvas too (get_lead_ref appointments.upcoming).'
        );
    }

    /**
     * The mirror is a convenience on top of the read, so a failure there is reported and the answer
     * still goes back with what eLead said.
     *
     * @param SalesActivities[] $activities
     * @return array<string, Event>
     */
    protected function mirror(
        Lead $lead,
        array $activities,
        Carbon $from,
        Carbon $to
    ): array {
        try {
            return new PullAppointmentsAction($lead)->apply($activities, $from, $to);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
