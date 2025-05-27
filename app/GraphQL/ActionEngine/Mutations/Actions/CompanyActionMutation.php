<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Actions;

use Exception;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;

class CompanyActionMutation
{
    /**
     * @todo move to action and add test
     */
    public function updateCompanyAction(mixed $rootValue, array $request): CompanyAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $action = CompanyAction::getByIdFromCompanyApp($request['input']['id'], $company, $app);

        if (! $user->isAdmin()) {
            throw new Exception('You do not have permission to update this action.');
        }

        $action->fill($request['input']);
        $action->saveOrFail();

        return $action;
    }
}
