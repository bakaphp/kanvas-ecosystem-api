<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Neuron\Tools;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Kanvas\Connectors\Elead\Actions\PullAppointmentsAction;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Neuron\Tools\Concerns\ReadsEleadForLead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Read eLead Activity History', category: 'elead')]
class ReadEleadActivityHistoryTool extends Tool
{
    use GuardsRepeatCalls;
    use ReadsEleadForLead;
    use TrackByInputs;

    private const int DEFAULT_DAYS_BACK = 14;

    protected string $name = 'read_elead_activity_history';

    protected ?string $description = 'Read the lead\'s activity history straight from eLead (the dealer CRM): scheduled and '
        . 'completed appointments, calls, emails and texts the sales team logged, with outcomes. Use it for "what '
        . 'has the dealership done with this lead", or when read_lead_activity (Kanvas only) does not answer. '
        . 'Defaults to the last 14 days plus the next 17; eLead caps any window at 31 days, so pass from_date '
        . 'and to_date to look at a different month. Dates are in the company timezone.';

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
                description: 'The ID of the lead whose eLead history to read.',
                required: true,
            ),
            new ToolProperty(
                name: 'from_date',
                type: PropertyType::STRING,
                description: 'Start of the window, YYYY-MM-DD in the company timezone. Default: 14 days ago.',
                required: false,
            ),
            new ToolProperty(
                name: 'to_date',
                type: PropertyType::STRING,
                description: 'End of the window, YYYY-MM-DD in the company timezone, at most 31 days after from_date. '
                    . 'Default: 31 days after from_date.',
                required: false,
            ),
            new ToolProperty(
                name: 'open_only',
                type: PropertyType::BOOLEAN,
                description: 'True to return only activities still open (scheduled, not completed or closed).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        ?string $from_date = null,
        ?string $to_date = null,
        ?bool $open_only = null
    ): array {
        $inputs = [
            'lead_id' => $lead_id,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'open_only' => $open_only,
        ];

        return $this->oncePerTurn(
            $inputs,
            fn (): array => $this->read(
                $lead_id,
                $from_date,
                $to_date,
                (bool) $open_only
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function read(
        int $leadId,
        ?string $fromDate,
        ?string $toDate,
        bool $openOnly
    ): array {
        $refusal = $this->customerSurfaceRefusal('The eLead activity history');
        if ($refusal !== null) {
            return $refusal;
        }

        $lead = $this->resolveEleadLeadOrError($leadId);
        if (is_array($lead)) {
            return $lead;
        }

        $timezone = $this->timezone($lead);

        try {
            [$from, $to] = $this->window($fromDate, $toDate, $timezone);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgs($e->getMessage());
        }

        try {
            $activities = $this->fetchActivities(
                $lead,
                $from,
                $to,
                $openOnly
            );
        } catch (Throwable $e) {
            return $this->eleadFailure($e, $lead);
        }

        usort($activities, fn (SalesActivities $a, SalesActivities $b): int => $b->dueDate() <=> $a->dueDate());

        $payload = [
            'lead_id' => $lead->getId(),
            'elead_opportunity_id' => $this->opportunityId($lead),
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'open_only' => $openOnly,
            'count' => count($activities),
            'activity' => array_map(
                fn (SalesActivities $activity): array => $this->presentActivity($activity, $timezone),
                $activities
            ),
        ];

        if ($activities === []) {
            return $this->noop(
                $payload,
                guidance: 'eLead logged nothing for this lead in that window. A different from_date/to_date '
                    . 'may hold activity; the same window will not.'
            );
        }

        return $this->ok($payload);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws InvalidArgumentException with the message the model should read
     */
    private function window(?string $fromDate, ?string $toDate, string $timezone): array
    {
        try {
            $from = $fromDate !== null
                ? Carbon::parse($fromDate, $timezone)->startOfDay()
                : Carbon::now($timezone)->subDays(self::DEFAULT_DAYS_BACK)->startOfDay();
            $to = $toDate !== null
                ? Carbon::parse($toDate, $timezone)->endOfDay()
                : $from->copy()->addDays(PullAppointmentsAction::WINDOW_DAYS);
        } catch (Throwable) {
            throw new InvalidArgumentException('from_date and to_date must be dates in YYYY-MM-DD form.');
        }

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('to_date is before from_date.');
        }

        if ($from->diffInDays($to) > PullAppointmentsAction::WINDOW_DAYS) {
            throw new InvalidArgumentException(
                'eLead answers at most ' . PullAppointmentsAction::WINDOW_DAYS . ' days per call; '
                . 'narrow the window or read it month by month.'
            );
        }

        return [$from, $to];
    }
}
