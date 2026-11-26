<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Event\Participants\Models\ParticipantPass;

class ReleasePassAction
{
    public function __construct(
        protected AppInterface $app,
        protected int $bookingVersionId
    ) {
    }

    public function execute(): int
    {
        $passes = ParticipantPass::query()
            ->fromApp($this->app)
            ->redeemedByBooking($this->bookingVersionId)
            ->get();

        foreach ($passes as $pass) {
            $this->release($pass);
        }

        return $passes->count();
    }

    private function release(ParticipantPass $pass): void
    {
        $pass->redemptions()->where('booking_event_version_id', $this->bookingVersionId)->delete();

        if ((int) $pass->redeemed_event_version_id === $this->bookingVersionId) {
            $pass->redeemed_event_version_id = null;
        }

        $liveBookingId = $pass->liveBookingIds()->first();

        $pass->update([
            'redeemed_at' => $liveBookingId === null ? null : $pass->redeemed_at,
            'redeemed_event_version_id' => $liveBookingId,
        ]);
    }
}
