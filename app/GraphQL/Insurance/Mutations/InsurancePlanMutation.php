<?php

declare(strict_types=1);

namespace App\GraphQL\Insurance\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Insurance\Actions\CreateInsurancePlanAction;
use Kanvas\Insurance\Actions\UpdateInsurancePlanAction;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\Insurer;

class InsurancePlanMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): InsurancePlan
    {
        $ctx = $this->actingContext();
        $insurer = Insurer::getByIdFromCompanyApp((int) $request['input']['insurer_id'], $ctx->company, $ctx->app);

        return new CreateInsurancePlanAction(
            InsurancePlanData::from($insurer, $request['input']),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): InsurancePlan
    {
        $plan = $this->findOwnedPlan((int) $request['id']);

        return new UpdateInsurancePlanAction(
            $plan,
            InsurancePlanData::forUpdate($plan, $request['input']),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        return $this->findOwnedPlan((int) $request['id'])->delete();
    }

    private function findOwnedPlan(int $id): InsurancePlan
    {
        $ctx = $this->actingContext();

        return InsurancePlan::query()
            ->notDeleted()
            ->fromInsurerTenant($ctx->app, $ctx->company)
            ->findOrFail($id);
    }
}
