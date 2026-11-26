<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Schedule;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Event\Events\Actions\UpdateTimeSlotCapacityAction;
use Kanvas\Event\Events\Models\TimeSlots;

class TimeSlotCapacityMutation
{
    use ResolvesActingContext;

    public function update(mixed $root, array $request): TimeSlots
    {
        $ctx = $this->actingContext();

        $timeSlot = TimeSlots::getByIdFromCompanyApp((int) $request['id'], $ctx->company, $ctx->app);

        return new UpdateTimeSlotCapacityAction($timeSlot, (int) $request['capacity'])->execute();
    }
}
