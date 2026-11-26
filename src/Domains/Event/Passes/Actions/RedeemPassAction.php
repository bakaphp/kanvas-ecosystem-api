<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Exceptions\ValidationException;

class RedeemPassAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected string $code,
        protected EventVersion $codeVersion,
        protected EventVersion $booking
    ) {
    }

    public function execute(): ParticipantPass
    {
        $pass = new FindPassByCodeAction(
            $this->app,
            $this->company,
            $this->code
        )->execute();

        if (! $pass || (int) $pass->event_version_id !== $this->codeVersion->getId()) {
            throw new ValidationException('Invalid access code.');
        }

        if ($pass->isRedeemedBy($this->booking)) {
            return $pass;
        }

        $this->claim($pass);

        return $pass->refresh();
    }

    private function claim(ParticipantPass $pass): void
    {
        DB::connection('event')->transaction(function () use ($pass): void {
            $locked = ParticipantPass::query()->whereKey($pass->getId())->lockForUpdate()->firstOrFail();
            $day = $this->booking->timeSlot?->localDate();

            if (
                ! $locked->isValid()
                || $locked->remainingUses(locking: true) < 1
                || ($day !== null && in_array($day, $locked->bookedDays(locking: true), true))
            ) {
                throw new ValidationException('Invalid access code.');
            }

            $locked->update([
                'redeemed_at' => Carbon::now(),
                'redeemed_event_version_id' => $this->booking->getId(),
            ]);
            $locked->recordRedemption($this->booking);
        });
    }
}
