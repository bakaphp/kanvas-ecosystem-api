<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
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

        if (! $pass->isRedeemable() || ! $this->claim($pass)) {
            throw new ValidationException('Invalid access code.');
        }

        return $pass->refresh();
    }

    private function claim(ParticipantPass $pass): bool
    {
        return ParticipantPass::query()
            ->whereKey($pass->getId())
            ->whereNull('revoked_at')
            ->where('redeemed_at', $pass->redeemed_at)
            ->where('redeemed_event_version_id', $pass->redeemed_event_version_id)
            ->update([
                'redeemed_at' => Carbon::now(),
                'redeemed_event_version_id' => $this->booking->getId(),
            ]) === 1;
    }
}
