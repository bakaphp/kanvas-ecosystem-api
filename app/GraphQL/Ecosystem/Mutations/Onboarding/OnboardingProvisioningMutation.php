<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Onboarding;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Ecosystem\Mutations\Onboarding\Concerns\ResolvesOnboardingRow;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Actions\ResolveOnboardingRequirementAction;
use Kanvas\Companies\Onboarding\DataTransferObject\OnboardingState;
use Kanvas\Companies\Onboarding\Enums\OnboardingStatusEnum;
use Kanvas\Companies\Onboarding\Enums\ProvisionStatusEnum;
use Kanvas\Companies\Onboarding\Jobs\ProvisionOnboardingItemsJob;
use Kanvas\Companies\Onboarding\Services\OnboardingProvisioningItemsService;
use Kanvas\Exceptions\ValidationException;

final class OnboardingProvisioningMutation
{
    use ResolvesActingContext;
    use ResolvesOnboardingRow;

    public function provision(mixed $root, array $request): OnboardingState
    {
        $ctx = $this->actingContext();
        $row = $this->row($ctx);

        /** @var Companies $company */
        $company = $ctx->company;

        if ((array) $row->dataGet('areas_selected', []) === []) {
            throw new ValidationException('Pick at least one area before provisioning');
        }

        // Every pending card shows WAITING the moment the mutation returns; the job overwrites each
        // one as it lands. An item a previous run finished stays READY and one mid-flight stays CREATING.
        $settled = [ProvisionStatusEnum::READY->value, ProvisionStatusEnum::CREATING->value];
        foreach (new OnboardingProvisioningItemsService()->forRow($row) as $item) {
            $slug = (string) $item['slug'];
            if (! in_array($row->dataGet("provisioning.{$slug}.status"), $settled, true)) {
                $row->dataSet("provisioning.{$slug}", [
                    'status' => ProvisionStatusEnum::WAITING->value,
                    'agent_id' => null,
                    'error' => null,
                ]);
            }
        }

        $row->advanceTo(OnboardingStatusEnum::PROVISIONING);

        ProvisionOnboardingItemsJob::dispatch($ctx->app, $company, $ctx->user);

        return $this->state($row->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveRequirement(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();

        return new ResolveOnboardingRequirementAction(
            $this->row($ctx),
            $ctx->user,
            (array) $request['input'],
        )->execute();
    }
}
