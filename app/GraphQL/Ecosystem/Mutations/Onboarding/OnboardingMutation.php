<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns\ResolvesOnboardingRow;
use Kanvas\Companies\Onboarding\Actions\SelectOnboardingAreasAction;
use Kanvas\Companies\Onboarding\Actions\SkipOnboardingAction;
use Kanvas\Companies\Onboarding\Actions\StartOnboardingAction;
use Kanvas\Companies\Onboarding\Actions\UpdateOnboardingProfileAction;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;

final class OnboardingMutation
{
    use ResolvesActingContext;
    use ResolvesOnboardingRow;

    /**
     * @return array<string, mixed>
     */
    public function start(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = (array) $request['input'];

        $workspace = new StartOnboardingAction(
            originApp: $ctx->app,
            user: $ctx->user,
            appName: (string) $input['app_name'],
            companyName: $input['company_name'] ?? null,
        )->execute();

        $workspace['onboarding'] = new OnboardingStateService()->state($workspace['onboarding']);

        return $workspace;
    }

    public function updateProfile(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();

        return $this->state(new UpdateOnboardingProfileAction(
            $this->row($ctx),
            $ctx->user,
            (array) $request['input'],
        )->execute());
    }

    public function selectAreas(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();

        return $this->state(new SelectOnboardingAreasAction(
            $this->row($ctx),
            $ctx->user,
            array_values((array) $request['areas']),
            $request['first_area'] ?? null,
        )->execute());
    }

    public function setStep(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();
        $row = $this->row($ctx);
        $row->current_step = (string) $request['step'];
        $row->saveOrFail();

        return $this->state($row);
    }

    public function skip(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();

        return $this->state(new SkipOnboardingAction($this->row($ctx), $ctx->user)->execute());
    }
}
