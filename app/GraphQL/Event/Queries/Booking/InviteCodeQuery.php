<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Booking;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\IPInfo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Event\Passes\Services\InviteCodeGate;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class InviteCodeQuery
{
    use ResolvesActingContext;

    public function verify(mixed $root, array $args): array
    {
        $ctx = $this->actingContext();
        $product = Products::query()->fromApp($ctx->app)->notDeleted()->find($args['product_id']);
        $access = EventAccess::forResource($product);

        if ($access === null) {
            return ['valid' => false, 'time_slot' => null];
        }

        $gate = new InviteCodeGate(
            $access,
            $ctx->app,
            $product->company,
            new PassAttemptLimiter('invite-code', $ctx->user->getId(), IPInfo::getClientIp())
        );
        $pass = $gate->inspect($args['code']);

        return [
            'valid' => $pass !== null,
            'time_slot' => $pass === null ? null : $this->slotOfTheCodeDay($product, $pass, $ctx->app),
        ];
    }

    private function slotOfTheCodeDay(Products $product, ParticipantPass $pass, Apps $app): ?TimeSlots
    {
        $variantIds = $product->variants()->pluck('id');
        $timezone = ResourceTimezoneService::resolve($product);
        $day = $pass->eventVersion->start_at->toDateString();

        return TimeSlots::query()
            ->fromApp($app)
            ->where('companies_id', $product->companies_id)
            ->whereBetween('start_at', [
                Carbon::parse($day, $timezone)->startOfDay()->utc(),
                Carbon::parse($day, $timezone)->endOfDay()->utc(),
            ])
            ->where(
                fn ($query) => $query
                    ->where(
                        fn ($resource) => $resource
                            ->where('resources_type', $product->getMorphClass())
                            ->where('resources_id', $product->getId())
                    )
                    ->orWhere(
                        fn ($resource) => $resource
                            ->where('resources_type', new Variants()->getMorphClass())
                            ->whereIn('resources_id', $variantIds)
                    )
            )
            ->orderBy('start_at')
            ->first();
    }
}
