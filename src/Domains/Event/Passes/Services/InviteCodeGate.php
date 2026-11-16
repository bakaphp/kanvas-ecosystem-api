<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\FindPassByCodeAction;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Exceptions\ValidationException;

class InviteCodeGate
{
    private const string INVALID_CODE_MESSAGE = 'Invalid access code.';

    public function __construct(
        private readonly EventAccess $access,
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly PassAttemptLimiter $limiter
    ) {
    }

    public function verify(?string $code, ?TimeSlots $timeSlot = null): ParticipantPass
    {
        return $this->inspect($code, $timeSlot) ?? throw new ValidationException(self::INVALID_CODE_MESSAGE);
    }

    public function inspect(?string $code, ?TimeSlots $timeSlot = null): ?ParticipantPass
    {
        $code = $this->requireCode($code);
        $this->limiter->assertNotLimited();

        $pass = new FindPassByCodeAction($this->app, $this->company, $code)->execute();

        if (! $pass || ! $this->isUsableFor($pass, $timeSlot)) {
            $this->limiter->recordFailure();

            return null;
        }

        return $pass;
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

    private function isUsableFor(ParticipantPass $pass, ?TimeSlots $timeSlot): bool
    {
        $version = $pass->eventVersion;

        if (! $version || (int) $version->event_id !== $this->access->eventId || ! $pass->isRedeemable()) {
            return false;
        }

        return $timeSlot === null || $version->start_at?->toDateString() === $this->localDayOf($timeSlot);
    }

    private function localDayOf(TimeSlots $timeSlot): string
    {
        return $timeSlot->start_at
            ->copy()
            ->setTimezone(ResourceTimezoneService::resolve($timeSlot->resource))
            ->toDateString();
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
