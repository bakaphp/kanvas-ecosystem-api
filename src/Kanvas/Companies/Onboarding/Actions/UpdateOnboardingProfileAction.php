<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Actions;

use Baka\Support\Str;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingResearchDispatcher;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Users\Models\Users;

final class UpdateOnboardingProfileAction
{
    /**
     * @param array<string, mixed> $input name, website, offering, job_role
     */
    public function __construct(
        private readonly CompanyOnboarding $row,
        private readonly Users $user,
        private readonly array $input,
    ) {
    }

    public function execute(): CompanyOnboarding
    {
        $company = $this->row->company;
        $previousWebsite = Str::trimToNull((string) $company->website);

        $name = Str::trimToNull($this->input['name'] ?? null);
        if ($name !== null) {
            $company->name = $name;
        }

        $website = Str::trimToNull($this->input['website'] ?? null);
        if ($website !== null) {
            $company->website = WebsiteUrl::normalize($website);
        }

        if ($company->isDirty()) {
            $company->saveOrFail();
        }

        $profile = (array) $this->row->dataGet('profile', []);
        foreach (['offering', 'job_role'] as $key) {
            if (array_key_exists($key, $this->input)) {
                $profile[$key] = Str::trimToNull($this->input[$key]);
            }
        }
        $this->row->dataSet('profile', $profile);

        if ($this->row->statusEnum() === OnboardingStatusEnum::STARTED) {
            $this->row->transitionTo(OnboardingStatusEnum::PROFILED);
        }

        $this->row->emitLedgerEvent(
            'onboarding.profile.updated',
            payload: ['fields' => array_keys($this->input), 'website' => $company->website ?: null],
            actorType: 'User',
            actorId: $this->user->getId(),
        );

        new OnboardingResearchDispatcher()->afterProfileUpdate($this->row, $this->user, $previousWebsite);

        return $this->row->refresh();
    }
}
