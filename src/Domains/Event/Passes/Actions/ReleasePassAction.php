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
        return ParticipantPass::query()
            ->fromApp($this->app)
            ->where('redeemed_event_version_id', $this->bookingVersionId)
            ->update([
                'redeemed_at' => null,
                'redeemed_event_version_id' => null,
            ]);
    }
}
