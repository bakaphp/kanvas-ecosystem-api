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

        $slots = new EventScheduleRepository()->getAvailableSlotsForUser(
            app: $lead->app,
            company: $company,
            user: $owner,
            from: $fromCarbon,
            to: $toCarbon,
            durationMinutes: $duration_minutes ?? 30,
            workingHours: $workingHours,
            limit: $limit ?? 10,
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
            'slots' => $slots,
            'slots_count' => count($slots),
        ];
    }
}
