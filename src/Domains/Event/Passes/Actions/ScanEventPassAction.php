<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Passes\DataTransferObject\PassScanOutcome;
use Kanvas\Event\Passes\Enums\PassScanResultEnum;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\OrderProvider;

class ScanEventPassAction
{
    public const int MAX_FAILURES_PER_MINUTE = 30;
    public const int DECAY_SECONDS = 60;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $scanner,
        protected CompanyInterface $scannerCompany,
        protected Products $gateProduct,
    ) {
    }

    public function execute(string $code): PassScanOutcome
    {
        $limiter = new PassAttemptLimiter(
            scope: 'event-pass-scan',
            userId: $this->scanner->getId(),
            maxFailures: self::MAX_FAILURES_PER_MINUTE,
            decaySeconds: self::DECAY_SECONDS
        );
        $limiter->assertNotLimited();

        $outcome = $this->evaluate($code);

        if ($outcome->result->isRejection()) {
            $limiter->recordFailure();
        }

        return $outcome;
    }

    private function evaluate(string $code): PassScanOutcome
    {
        $pass = new FindPassByCodeAction($this->app, $this->gateProduct->company, $code)->execute();
        $booking = $pass?->eventVersion;

        if (! $pass || ! $booking || $booking->time_slot_id === null) {
            return $this->reject(PassScanResultEnum::INVALID_CODE);
        }

        if (! $this->scannerMayCheck($booking)) {
            return $this->reject(PassScanResultEnum::FORBIDDEN, $pass);
        }

        if (! $this->isActive($pass, $booking)) {
            return $this->reject(PassScanResultEnum::NOT_ACTIVE, $pass);
        }

        $timeSlot = $booking->timeSlot;

        if (! $timeSlot || ! $this->belongsToGate($timeSlot)) {
            return $this->reject(PassScanResultEnum::WRONG_RESOURCE, $pass);
        }

        if (! Carbon::now()->betweenIncluded($timeSlot->start_at, $timeSlot->end_at)) {
            return $this->reject(PassScanResultEnum::OUTSIDE_WINDOW, $pass);
        }

        return $this->admit($pass, $booking, $timeSlot);
    }

    private function admit(ParticipantPass $pass, EventVersion $booking, TimeSlots $timeSlot): PassScanOutcome
    {
        try {
            DB::connection('event')->transaction(function () use ($pass, $booking) {
                $this->record(PassScanResultEnum::ADMITTED, $pass, $booking->getId());

                ParticipantPass::query()
                    ->where('event_version_id', $booking->getId())
                    ->whereNull('used_date')
                    ->update(['used_date' => Carbon::now()]);
            });
            $result = PassScanResultEnum::ADMITTED;
        } catch (UniqueConstraintViolationException) {
            $this->record(PassScanResultEnum::ALREADY_ADMITTED, $pass);
            $result = PassScanResultEnum::ALREADY_ADMITTED;
        }

        return new PassScanOutcome($result, $this->summarize($pass, $booking, $timeSlot));
    }

    private function reject(PassScanResultEnum $result, ?ParticipantPass $pass = null): PassScanOutcome
    {
        $this->record($result, $pass);

        return new PassScanOutcome($result);
    }

    private function record(PassScanResultEnum $result, ?ParticipantPass $pass, ?int $admittedBookingId = null): void
    {
        ParticipantPassScan::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->scannerCompany->getId(),
            'participant_passes_id' => $pass?->getId(),
            'event_version_id' => $pass?->event_version_id,
            'products_id' => $this->gateProduct->getId(),
            'users_id' => $this->scanner->getId(),
            'result' => $result,
            'admitted_event_version_id' => $admittedBookingId,
            'scanned_at' => Carbon::now(),
        ]);
    }

    private function scannerMayCheck(EventVersion $booking): bool
    {
        if ($this->gateProduct->companies_id === $this->scannerCompany->getId()) {
            return true;
        }

        return OrderProvider::query()
            ->whereIn('order_id', $booking->event->orders()->select('id'))
            ->where('company_id', $this->scannerCompany->getId())
            ->exists();
    }

    private function isActive(ParticipantPass $pass, EventVersion $booking): bool
    {
        if ($pass->revoked_at !== null || now()->gt(Carbon::parse($pass->expiration_date)->endOfDay())) {
            return false;
        }

        $bookingIsLive = EventVersion::query()
            ->whereKey($booking->getId())
            ->where('is_deleted', 0)
            ->notCancelled()
            ->exists();

        return $bookingIsLive
            && ! $booking->event->orders()->whereIn('status', OrderStatusEnum::closedValues())->exists();
    }

    private function belongsToGate(TimeSlots $timeSlot): bool
    {
        $resource = $timeSlot->resource;

        return match (true) {
            $resource instanceof Variants => (int) $resource->products_id === $this->gateProduct->getId(),
            $resource instanceof Products => $resource->getId() === $this->gateProduct->getId(),
            default => false,
        };
    }

    private function summarize(ParticipantPass $pass, EventVersion $booking, TimeSlots $timeSlot): array
    {
        $guest = $pass->participant ?? $booking->participants()->first();

        return [
            'plate' => $booking->metadata['vehiclePlate'] ?? null,
            'arrival_hour' => $booking->metadata['arrival_hour'] ?? null,
            'date' => $timeSlot->start_at->toDateString(),
            'guest' => $guest?->people?->getName(),
        ];
    }
}
