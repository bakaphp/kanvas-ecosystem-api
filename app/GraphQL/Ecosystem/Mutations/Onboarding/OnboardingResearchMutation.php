<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns\ResolvesOnboardingRow;
use Kanvas\Companies\Onboarding\Services\OnboardingResearchDispatcher;

final class OnboardingResearchMutation
{
    use ResolvesActingContext;
    use ResolvesOnboardingRow;

    /**
     * @return array<string, mixed>
     */
    public function research(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $row = $this->row($ctx);

        new OnboardingResearchDispatcher()->force(
            $row,
            $ctx->user,
            (string) $request['website'],
            (bool) ($request['force'] ?? false),
        );

        return $this->state($row->refresh())->research;
    }
}
