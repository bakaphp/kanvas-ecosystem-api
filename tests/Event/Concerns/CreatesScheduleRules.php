<?php

declare(strict_types=1);

namespace Tests\Event\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\ScheduleRules;

trait CreatesScheduleRules
{
    private function addScheduleRule(Model $resource, string $day, array $attributes): ScheduleRules
    {
        return ScheduleRules::create([
            'apps_id' => $this->apps->getId(),
            'companies_id' => $this->company->getId(),
            'resources_id' => $resource->getId(),
            'resources_type' => $resource->getMorphClass(),
            'operation_day' => $day,
            'start_at' => Carbon::parse('2026-12-01', 'UTC'),
            'end_at' => Carbon::parse('2027-01-01', 'UTC'),
            'rrule' => 'RRULE:FREQ=WEEKLY',
            'slot_duration_min' => 60,
            ...$attributes,
        ]);
    }
}
