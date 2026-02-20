<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Schedule;

use App\GraphQL\Event\Traits\ResolvesScheduleResource;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildScheduleResponseAction;
use Kanvas\Event\Events\Actions\SetResourceScheduleAction;
use Kanvas\Event\Events\Enums\ScheduleTypeEnum;

class ResourceScheduleMutation
{
    use ResolvesScheduleResource;

    public function set(mixed $root, array $req): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = $req['input'];
        $resource = $this->getResource($input['resources_type'], $input['resources_id']);

        $days = collect($input['days'])->keyBy('day')->map(fn (array $d) => [
            'active' => $d['active'],
            'open' => $d['open'] ?? null,
            'close' => $d['close'] ?? null,
            'periods' => $d['periods'] ?? null,
        ])->all();

        $scheduleType = ScheduleTypeEnum::from(strtolower($input['schedule_type']));

        $rules = new SetResourceScheduleAction(
            resource: $resource,
            app: $app,
            company: $company,
            days: $days,
            scheduleType: $scheduleType,
            slotDurationMin: $input['slot_duration_min'] ?? 60,
            capacityOverride: $input['capacity_override'] ?? null,
        )->execute();

        return new BuildScheduleResponseAction($resource, $app, $rules)->execute();
    }
}
