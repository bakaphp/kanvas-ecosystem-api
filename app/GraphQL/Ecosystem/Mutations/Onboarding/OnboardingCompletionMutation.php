<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns\ResolvesOnboardingRow;
use Kanvas\Companies\Onboarding\Actions\CompleteOnboardingAction;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;

final class OnboardingCompletionMutation
{
    use ResolvesActingContext;
    use ResolvesOnboardingRow;

    public function complete(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();

        return $this->state(new CompleteOnboardingAction($this->row($ctx), $ctx->user)->execute());
    }
}
