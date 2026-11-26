<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\FindPassByCodeAction;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Event\Passes\DataTransferObject\InviteCodeCheck;
use Kanvas\Event\Passes\Enums\InviteCodeCheckReasonEnum;
use Kanvas\Exceptions\ValidationException;

class InviteCodeGate
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly PassAttemptLimiter $limiter
    ) {
    }

    public function verify(?string $code, ?TimeSlots $timeSlot = null): ParticipantPass
    {
        $check = $this->check($code, $timeSlot);

        if (! $check->isValid()) {
            throw new ValidationException($check->reason->message());
        }

        return $check->pass;
    }

    public function check(?string $code, ?TimeSlots $timeSlot = null): InviteCodeCheck
    {
        $code = $this->requireCode($code);
        $this->limiter->assertNotLimited();

        $pass = new FindPassByCodeAction($this->app, $this->company, $code)->execute();
        $reason = $this->reasonFor($pass, $timeSlot);

        if ($reason !== InviteCodeCheckReasonEnum::VALID) {
            $this->limiter->recordFailure();
        }

        return new InviteCodeCheck($reason, $pass);
    }

    public function redeem(?string $code, TimeSlots $timeSlot, EventVersion $booking): ParticipantPass
    {
        $pass = $this->verify($code, $timeSlot);

        try {
            return new RedeemPassAction(
                app: $this->app,
                company: $this->company,
                code: (string) $code,
                codeVersion: $pass->eventVersion,
                booking: $booking
            )->execute();
        } catch (ValidationException $exception) {
            $this->limiter->recordFailure();

            throw $exception;
        }
    }

    private function reasonFor(?ParticipantPass $pass, ?TimeSlots $timeSlot): InviteCodeCheckReasonEnum
    {
        $version = $pass?->eventVersion;
        $day = $timeSlot?->localDate();

        $belongsToTheEvent = $version !== null
            && (int) $version->event_id === $this->access->eventId
            && $pass->isValid()
            && ! $this->access->redeemWindowClosed()
            && ($day === null || in_array($day, $pass->allowedDays(), true));

        return match (true) {
            ! $belongsToTheEvent => InviteCodeCheckReasonEnum::INVALID,
            $pass->remainingUses() < 1 => InviteCodeCheckReasonEnum::USED,
            $day !== null && in_array($day, $pass->bookedDays(), true) => InviteCodeCheckReasonEnum::USED_THIS_DAY,
            default => InviteCodeCheckReasonEnum::VALID,
        };
    }

    private function requireCode(?string $code): string
    {
        $code = Str::trimToNull($code);

        if ($code === null) {
            throw new ValidationException('An invite code is required for this booking.');
        }

        return $code;
    }
}
