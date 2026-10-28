<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Concerns\FlipsWelcomeFlag;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Filesystem\Models\Filesystem;

final class OnboardingQuery
{
    use FlipsWelcomeFlag;
    use ResolvesActingContext;

    public function companyOnboarding(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();
        /** @var Companies $company */
        $company = $ctx->company;
        $service = new OnboardingStateService();
        $row = $service->forCompany($ctx->app, $company, $ctx->user);

        // A later admin of a finished company skips the flow; their own welcome flag follows.
        if ($row->isTerminal()) {
            $this->flipWelcome($ctx->user, $ctx->app);
        }

        return $service->state($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function areas(mixed $root, array $request): array
    {
        return $this->companyOnboarding($root, $request)->areas;
    }

    /**
     * @return Collection<int, Filesystem>
     */
    public function knowledgeDocuments(OnboardingState $root): Collection
    {
        return $root->row->files()->get();
    }
}
