<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Event\Events\Repositories\EventScheduleRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'User Availability')]
class UserAvailabilityTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_user_availability',
            description: 'List free time slots for the lead owner (salesperson) in the given window, honoring company work hours. '
                . 'Use this BEFORE proposing meeting times so the slots you suggest are real. '
                . 'DEFAULTS: if you do not know today\'s date or the prospect did not specify a window, OMIT the `from` '
                . 'and `to` parameters — the tool will default to "now through the next 10 business days". '
                . 'NEVER pass dates from before the current date in your context. '
                . 'Returns an array of {start, end} slots in ISO-8601 in the company timezone.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead. Used to resolve company, owner, work hours, and timezone.',
                required: true,
            ),
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'Window start in company timezone, YYYY-MM-DD or YYYY-MM-DD HH:MM. Defaults to now.',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Window end in company timezone, YYYY-MM-DD or YYYY-MM-DD HH:MM. Defaults to 10 business days from now.',
                required: false,
            ),
            new ToolProperty(
                name: 'duration_minutes',
                type: PropertyType::INTEGER,
                description: 'Length of each slot to look for, in minutes. Defaults to 30.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of slots to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        ?string $from = null,
        ?string $to = null,
        ?int $duration_minutes = null,
        ?int $limit = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $company = $lead->company;
        $owner = $lead->owner;

        if ($owner === null) {
            return [
                'status' => 'error',
                'message' => 'Lead has no owner. Cannot check availability without a salesperson assigned.',
            ];
        }

        $tz = $company->timezone ?? 'UTC';

        try {
            $fromCarbon = $from !== null
                ? Carbon::parse($from, $tz)
                : Carbon::now($tz);

            $toCarbon = $to !== null
                ? Carbon::parse($to, $tz)
                : $fromCarbon->copy()->addWeekdays(10)->endOfDay();
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Invalid from/to. Use YYYY-MM-DD or YYYY-MM-DD HH:MM. Error: ' . $e->getMessage(),
            ];
        }

        if ($toCarbon->lte($fromCarbon)) {
            return [
                'status' => 'error',
                'message' => 'to must be after from.',
            ];
        }

        $workingHours = $company->get(CompanyConfigurationEnum::WORKING_HOURS->value) ?? [];
        if (! is_array($workingHours)) {
            $workingHours = [];
        }

        // Without WORKING_HOURS configured the repository would return 0 slots
        // (no window to compute against), which the LLM mis-reads as "owner is
        // booked solid". Fall back to standard Mon-Fri 9-5 so the LLM gets real
        // slots and we surface the fallback in the response.
        $workHoursSource = 'company_config';
        if ($workingHours === []) {
            $workingHours = [
                'monday' => '09:00-17:00',
                'tuesday' => '09:00-17:00',
                'wednesday' => '09:00-17:00',
                'thursday' => '09:00-17:00',
                'friday' => '09:00-17:00',
            ];
            $workHoursSource = 'default_fallback_mon_fri_9_5';
        }

        $repo = new EventScheduleRepository();

        $slots = $repo->getAvailableSlotsForUser(
            app: $lead->app,
            company: $company,
            user: $owner,
            from: $fromCarbon,
            to: $toCarbon,
            durationMinutes: $duration_minutes ?? 30,
            workingHours: $workingHours,
            limit: $limit ?? 10,
        );

        // Also surface the scheduled events directly. Even when slots is empty
        // (e.g. work hours weirdness), the LLM can see if the owner has 0 or
        // many scheduled events and reason about availability accordingly.
        $busy = $repo->getScheduled($lead->app, $company, $fromCarbon, $toCarbon, $owner);
        $scheduledEvents = $busy->take(10)->map(fn (object $b): array => [
            'start' => $b->start->toIso8601String(),
            'end' => $b->end->toIso8601String(),
            'name' => $b->event_name,
        ])->all();

        $interpretation = $this->buildInterpretation(
            slotsCount: count($slots),
            scheduledCount: $busy->count(),
            workHoursSource: $workHoursSource,
        );

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'owner_user_id' => $owner->getId(),
            'company_timezone' => $tz,
            'window' => [
                'from' => $fromCarbon->toIso8601String(),
                'to' => $toCarbon->toIso8601String(),
            ],
            'work_hours_source' => $workHoursSource,
            'scheduled_events_count' => $busy->count(),
            'scheduled_events' => $scheduledEvents,
            'slots' => $slots,
            'slots_count' => count($slots),
            'interpretation' => $interpretation,
        ];
    }

    private function buildInterpretation(int $slotsCount, int $scheduledCount, string $workHoursSource): string
    {
        if ($slotsCount > 0) {
            return 'Owner has open slots — propose 2-3 from the slots array to the prospect.';
        }

        if ($scheduledCount === 0) {
            return 'Owner has zero scheduled events in this window AND zero free slots were computed. '
                . 'This almost certainly means work hours could not be resolved — the salesperson is '
                . 'NOT actually fully booked. Do NOT tell the prospect the calendar is tight. '
                . 'Either ask the prospect for their preferred time and propose it, or call handoff_lead '
                . 'so a human can schedule manually.';
        }

        return "Owner has {$scheduledCount} scheduled event(s) in this window and no free slots within work hours. "
            . 'Either widen the window (pass `to` further out), reduce duration_minutes, or call handoff_lead.';
    }
}
