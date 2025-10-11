<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\ScheduleRules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\SystemModules\Models\SystemModules;

class ScheduleRulesManagementMutation
{
    public function create(mixed $root, array $req): ScheduleRules
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $entity = $this->getEntity($req['input']['resources_type'], $req['input']['resources_id']);

        $scheduleRule = ScheduleRules::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'resources_id' => $entity->id,
            'resources_type' => $entity->getMorphClass(),
            'start_at' => $req['input']['start_at'],
            'end_at' => $req['input']['end_at'] ?? null,
            'rrule' => $req['input']['rrule'],
            'day_rrule' => $req['input']['day_rrule'] ?? null,
            'slot_duration_min' => $req['input']['slot_duration_min'],
            'lead_time_min' => $req['input']['lead_time_min'] ?? 0,
            'cutoff_time_min' => $req['input']['cutoff_time_min'] ?? 0,
            'capacity_override' => $req['input']['capacity_override'] ?? null,
            'metadata' => $req['input']['metadata'] ?? null,
        ]);

        // Dispatch job to generate time slots
        $windowFrom = Carbon::now();
        $windowTo = Carbon::now()->addYear();

        dispatch(new GenerateTimeSlots(
            $entity->id,
            $scheduleRule->id,
            $windowFrom,
            $windowTo
        ));

        return $scheduleRule;
    }

    public function update(mixed $root, array $req): ScheduleRules
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $scheduleRule = ScheduleRules::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app);

        // Delete all upcoming time slots related to this schedule rule
        $scheduleRule->deleteUpcomingTimeSlots();

        $scheduleRule->update([
            'resources_id' => $req['input']['resources_id'] ?? $scheduleRule->resources_id,
            'resources_type' => $req['input']['resources_type'] ?? $scheduleRule->resources_type,
            'start_at' => $req['input']['start_at'] ?? $scheduleRule->start_at,
            'end_at' => $req['input']['end_at'] ?? $scheduleRule->end_at,
            'rrule' => $req['input']['rrule'] ?? $scheduleRule->rrule,
            'day_rrule' => $req['input']['day_rrule'] ?? $scheduleRule->day_rrule,
            'slot_duration_min' => $req['input']['slot_duration_min'] ?? $scheduleRule->slot_duration_min,
            'lead_time_min' => $req['input']['lead_time_min'] ?? $scheduleRule->lead_time_min,
            'cutoff_time_min' => $req['input']['cutoff_time_min'] ?? $scheduleRule->cutoff_time_min,
            'capacity_override' => $req['input']['capacity_override'] ?? $scheduleRule->capacity_override,
            'metadata' => $req['input']['metadata'] ?? $scheduleRule->metadata,
        ]);

        $windowFrom = Carbon::now();
        $windowTo = Carbon::now()->addYear();

        dispatch(new GenerateTimeSlots(
            $scheduleRule->resources_id,
            $scheduleRule->id,
            $windowFrom,
            $windowTo
        ));

        return $scheduleRule;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $scheduleRule = ScheduleRules::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app);

        // Delete all upcoming time slots related to this schedule rule
        $scheduleRule->deleteUpcomingTimeSlots();

        return $scheduleRule->delete();
    }

    private function getEntity(string $entityType, int|string $entityId): Model
    {
        $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($entityType);
        return $entityClass::getById($entityId);
    }
}
