<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Onboarding\Concerns\FlipsWelcomeFlag;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

final class SkipOnboardingAction
{
    use FlipsWelcomeFlag;

    public function __construct(
        private readonly CompanyOnboarding $row,
        private readonly Users $user,
    ) {
    }

    public function execute(): CompanyOnboarding
    {
        $app = $this->row->app;

        if ($this->row->statusEnum() === OnboardingStatusEnum::COMPLETED) {
            throw new ValidationException('Onboarding is already completed; there is nothing to skip');
        }

        if ($this->row->statusEnum() !== OnboardingStatusEnum::SKIPPED) {
            $step = $this->row->current_step;
            $this->row->skipped_at = Carbon::now();
            $this->row->transitionTo(OnboardingStatusEnum::SKIPPED);
            $this->row->emitLedgerEvent(
                'onboarding.skipped',
                payload: ['step' => $step],
                actorType: 'User',
                actorId: $this->user->getId(),
            );
        }

        $this->flipWelcome($this->user, $app);

        return $this->row->refresh();
    }
}
