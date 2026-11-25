<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Passes\Actions\ScanEventPassAction;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanSourceEnum;
use Kanvas\Inventory\Products\Models\Products;

class EventPassScanMutation
{
    use ResolvesActingContext;

    public function scan(mixed $root, array $request): PassScanOutcome
    {
        Gate::authorize('validate-pass');

        $ctx = $this->actingContext();
        $product = Products::getById((int) $request['product_id'], $ctx->app);

        return new ScanEventPassAction(
            $ctx->app,
            $ctx->user,
            $ctx->company,
            $product
        )->execute((string) $request['code']);
    }

    public function admit(mixed $root, array $request): PassScanOutcome
    {
        Gate::authorize('validate-pass');

        $ctx = $this->actingContext();
        $product = Products::getById((int) $request['product_id'], $ctx->app);
        $now = Carbon::now();
        $admittedAt = isset($request['admitted_at'])
            ? $this->lotLocalTime($request['admitted_at'], $product)
            : $now;

        return new ScanEventPassAction(
            $ctx->app,
            $ctx->user,
            $ctx->company,
            $product,
            PassScanSourceEnum::MANUAL,
            $admittedAt->gt($now) ? $now : $admittedAt
        )->execute($this->passCodeOfReference((string) $request['reference'], $ctx->app));
    }

    private function lotLocalTime(Carbon $wallClock, Products $product): Carbon
    {
        return $wallClock->copy()->shiftTimezone(ResourceTimezoneService::resolve($product))->utc();
    }

    private function passCodeOfReference(string $reference, Apps $app): string
    {
        $booking = EventVersion::query()
            ->fromApp($app)
            ->where('reference', strtoupper(trim($reference)))
            ->first();

        return $booking?->plainBookingPassCode() ?? $reference;
    }
}
