<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Booking;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\IPInfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Event\Passes\Enums\InviteCodeCheckReasonEnum;
use Kanvas\Event\Passes\Services\InviteCodeGate;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Inventory\Products\Models\Products;

class InviteCodeQuery
{
    use ResolvesActingContext;

    public function verify(mixed $root, array $args): array
    {
        $ctx = $this->actingContext();
        $product = Products::query()->fromApp($ctx->app)->notDeleted()->find($args['product_id']);
        $access = EventAccess::forResource($product);

        if ($access === null) {
            return $this->answer(InviteCodeCheckReasonEnum::INVALID);
        }

        $gate = new InviteCodeGate(
            $access,
            $ctx->app,
            $product->company,
            new PassAttemptLimiter('invite-code', $ctx->user->getId(), IPInfo::getClientIp())
        );
        $check = $gate->check($args['code']);

        if (! $check->isValid()) {
            return $this->answer($check->reason);
        }

        $timeSlots = $this->slotsOfTheDaysLeft($product, $check->pass, $ctx->app);
        $allFull = $timeSlots->isNotEmpty() && $timeSlots->every(fn (TimeSlots $slot) => $slot->isFullyBooked());

        return $this->answer(
            $allFull ? InviteCodeCheckReasonEnum::FULL : InviteCodeCheckReasonEnum::VALID,
            $check->pass->remainingUses(),
            $timeSlots
        );
    }

    private function answer(
        InviteCodeCheckReasonEnum $reason,
        int $remainingUses = 0,
        ?Collection $timeSlots = null
    ): array {
        return [
            'valid' => $reason === InviteCodeCheckReasonEnum::VALID,
            'reason' => $reason->value,
            'remaining_uses' => $remainingUses,
            'time_slots' => $timeSlots?->all() ?? [],
        ];
    }

    private function slotsOfTheDaysLeft(Products $product, ParticipantPass $pass, Apps $app): Collection
    {
        $timezone = ResourceTimezoneService::resolve($product);
        $daysLeft = array_diff($pass->allowedDays(), $pass->bookedDays());

        return collect($daysLeft)
            ->map(fn (string $day) => $this->slotOfDay($product, $day, $timezone, $app))
            ->filter()
            ->values();
    }

    private function slotOfDay(
        Products $product,
        string $day,
        string $timezone,
        Apps $app
    ): ?TimeSlots {
        return TimeSlots::query()
            ->fromApp($app)
            ->where('companies_id', $product->companies_id)
            ->whereBetween('start_at', [
                Carbon::parse($day, $timezone)->startOfDay()->utc(),
                Carbon::parse($day, $timezone)->endOfDay()->utc(),
            ])
            ->forProduct($product)
            ->orderBy('start_at')
            ->first();
    }
}
