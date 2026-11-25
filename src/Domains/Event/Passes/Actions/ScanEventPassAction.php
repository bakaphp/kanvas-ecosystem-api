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
use Kanvas\Event\Passes\Enums\PassScanSourceEnum;
use Kanvas\Event\Passes\Gates\PassScanDecisionGate;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class ScanEventPassAction
{
    public const int MAX_FAILURES_PER_MINUTE = 30;
    public const int DECAY_SECONDS = 60;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $scanner,
        protected CompanyInterface $scannerCompany,
        protected Products $gateProduct,
        protected PassScanSourceEnum $source = PassScanSourceEnum::QR,
        protected ?Carbon $scannedAt = null,
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

        $laterUse = $this->isLaterUse($pass);

        if (! $this->isActive($pass, $booking, $laterUse)) {
            return $this->reject(PassScanResultEnum::NOT_ACTIVE, $pass);
        }

        $timeSlot = $booking->timeSlot;

        if (! $timeSlot || ! $this->belongsToGate($timeSlot)) {
            return $this->reject(PassScanResultEnum::WRONG_RESOURCE, $pass);
        }

        if (! $laterUse && ! $this->moment()->betweenIncluded($timeSlot->start_at, $timeSlot->end_at)) {
            return $this->reject(PassScanResultEnum::OUTSIDE_WINDOW, $pass);
        }

        $decidedUse = $this->decideUse($pass, $booking);

        if ($decidedUse === false) {
            return $this->reject(PassScanResultEnum::NOT_AUTHORIZED, $pass);
        }

        return $this->admit(
            $pass,
            $booking,
            $timeSlot,
            $decidedUse
        );
    }

    private function decideUse(ParticipantPass $pass, EventVersion $booking): int|false|null
    {
        $decisionFromUse = PassScanDecisionGate::decisionFromUse($pass);

        if ($decisionFromUse === null) {
            return null;
        }

        $useNumber = $pass->use_count + 1;

        if ($decisionFromUse === PassScanDecisionGate::UNREADABLE) {
            return false;
        }

        if ($useNumber < $decisionFromUse) {
            return null;
        }

        $allowed = new PassScanDecisionGate($this->app, $this->scanner, $this->gateProduct)
            ->allows($pass, $booking, $useNumber);

        return $allowed ? $useNumber : false;
    }

    private function lacksDecisionFor(ParticipantPass $locked, int $useNumber, ?int $decidedUse): bool
    {
        $decisionFromUse = PassScanDecisionGate::decisionFromUse($locked);

        if ($decisionFromUse === null) {
            return false;
        }

        return $decisionFromUse === PassScanDecisionGate::UNREADABLE
            || ($useNumber >= $decisionFromUse && $useNumber !== $decidedUse);
    }

    private function admit(
        ParticipantPass $pass,
        EventVersion $booking,
        TimeSlots $timeSlot,
        ?int $decidedUse
    ): PassScanOutcome {
        $result = PassScanResultEnum::ADMITTED;
        $scannedAt = $this->moment();
        $admittedUse = null;

        try {
            DB::connection('event')->transaction(function () use ($pass, $booking, $decidedUse, &$result, &$admittedUse): void {
                $locked = ParticipantPass::query()->whereKey($pass->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->revoked_at !== null) {
                    $result = PassScanResultEnum::NOT_ACTIVE;

                    return;
                }

                if ($locked->use_count >= ($locked->max_uses ?? 1)) {
                    $result = PassScanResultEnum::ALREADY_ADMITTED;

                    return;
                }

                $useNumber = $locked->use_count + 1;

                if ($this->lacksDecisionFor($locked, $useNumber, $decidedUse)) {
                    $result = PassScanResultEnum::NOT_AUTHORIZED;

                    return;
                }

                $locked->use_count = $useNumber;
                $locked->save();
                $admittedUse = ['use_number' => $useNumber, 'max_uses' => $locked->max_uses ?? 1];

                $this->record(
                    PassScanResultEnum::ADMITTED,
                    $pass,
                    $useNumber === 1 ? $booking->getId() : null,
                    $useNumber
                );

                if ($useNumber === 1) {
                    ParticipantPass::query()
                        ->where('event_version_id', $booking->getId())
                        ->whereNull('used_date')
                        ->update(['used_date' => $this->moment()]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $result = PassScanResultEnum::ALREADY_ADMITTED;
        }

        if ($result === PassScanResultEnum::NOT_ACTIVE || $result === PassScanResultEnum::NOT_AUTHORIZED) {
            return $this->reject($result, $pass);
        }

        if ($result === PassScanResultEnum::ALREADY_ADMITTED) {
            $this->record($result, $pass);
        }

        if ($result === PassScanResultEnum::ADMITTED && $admittedUse !== null) {
            $this->announceScan(
                $pass,
                $booking,
                $admittedUse,
                $scannedAt
            );
        }

        return new PassScanOutcome($result, $this->summarize($pass, $booking, $timeSlot));
    }

    private function announceScan(
        ParticipantPass $pass,
        EventVersion $booking,
        array $admittedUse,
        Carbon $scannedAt
    ): void {
        try {
            $booking->fireWorkflowAfterCommit(
                WorkflowEnum::PASS_SCANNED->value,
                [
                    'app' => $this->app,
                    ...$admittedUse,
                    'scanned_at' => $scannedAt->toIso8601String(),
                    'products_id' => $this->gateProduct->getId(),
                    'participant_passes_id' => $pass->getId(),
                ]
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function moment(): Carbon
    {
        return $this->scannedAt ?? Carbon::now();
    }

    private function isLaterUse(ParticipantPass $pass): bool
    {
        return ($pass->max_uses ?? 1) > 1 && $pass->use_count >= 1;
    }

    private function reject(PassScanResultEnum $result, ?ParticipantPass $pass = null): PassScanOutcome
    {
        $this->record($result, $pass);

        return new PassScanOutcome($result);
    }

    private function record(
        PassScanResultEnum $result,
        ?ParticipantPass $pass,
        ?int $admittedBookingId = null,
        ?int $useNumber = null
    ): void {
        ParticipantPassScan::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->scannerCompany->getId(),
            'participant_passes_id' => $pass?->getId(),
            'event_version_id' => $pass?->event_version_id,
            'products_id' => $this->gateProduct->getId(),
            'users_id' => $this->scanner->getId(),
            'result' => $result,
            'admitted_event_version_id' => $admittedBookingId,
            'use_number' => $useNumber,
            'source' => $this->source,
            'scanned_at' => $this->moment(),
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

    private function isActive(ParticipantPass $pass, EventVersion $booking, bool $ignoreExpiry): bool
    {
        $passIsUsable = $ignoreExpiry ? $pass->revoked_at === null : $pass->isValid();

        return $passIsUsable
            && EventVersion::query()->whereKey($booking->getId())->live()->exists()
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
            'reference' => $booking->reference,
            'plate' => $booking->metadata['vehiclePlate'] ?? null,
            'arrival_hour' => $booking->metadata['arrival_hour'] ?? null,
            'date' => $timeSlot->start_at->toDateString(),
            'guest' => $guest?->people?->getName(),
        ];
    }
}
