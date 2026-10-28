<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns\ResolvesOnboardingRow;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Services\OnboardingResearchDispatcher;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;

final class OnboardingResearchMutation
{
    use HasMutationUploadFiles;
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

    /**
     * Files attach to the onboarding row, never to the company: only these are indexed company-wide,
     * by the attach-file rule on CompanyOnboarding.
     */
    public function upload(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();
        $row = $this->row($ctx);

        $this->uploadFileToEntity(
            $row,
            $ctx->app,
            $ctx->user,
            $request,
        );

        return $this->state($row->refresh());
    }
}
