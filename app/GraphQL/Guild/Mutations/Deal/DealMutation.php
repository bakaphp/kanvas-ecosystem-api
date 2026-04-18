<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Deal;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class DealMutation
{
    public function create(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return new CreateDealAction(
            DealData::fromMultiple($user, $app, $company, $request['input'])
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Deal
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateDealAction(
            $deal,
            DealData::fromMultiple($user, $app, $company, $request['input'], $deal)
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var Deal $deal */
        $deal = Deal::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $deal->fireWorkflow(WorkflowEnum::DELETED->value, true);

        return $deal->softDelete();
    }
}
