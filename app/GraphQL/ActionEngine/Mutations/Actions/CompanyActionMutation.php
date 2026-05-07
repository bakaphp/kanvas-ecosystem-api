<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Actions;

use Kanvas\ActionEngine\Actions\Actions\CreateCompanyActionAction;
use Kanvas\ActionEngine\Actions\Actions\UpdateCompanyActionAction;
use Kanvas\ActionEngine\Actions\DataTransferObject\CompanyAction as CompanyActionData;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;

class CompanyActionMutation
{
    public function create(mixed $rootValue, array $request): CompanyAction
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = $request['input'];
        $action = Action::getById((int) $input['actions_id'], $app);

        return new CreateCompanyActionAction(
            new CompanyActionData(
                action: $action,
                name: $input['name'],
                description: $input['description'] ?? null,
                form_config: $input['form_config'] ?? null,
                config: $input['config'] ?? null,
                is_active: $input['is_active'] ?? null,
                is_published: $input['is_published'] ?? null,
                weight: isset($input['weight']) ? (float) $input['weight'] : null,
                parent_id: isset($input['parent_id']) ? (int) $input['parent_id'] : null,
            ),
            $user,
            $app,
            $company,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): CompanyAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $companyAction = CompanyAction::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $input = $request['input'];

        return new UpdateCompanyActionAction(
            $companyAction,
            new CompanyActionData(
                action: $companyAction->action,
                name: $input['name'],
                description: $input['description'] ?? null,
                form_config: $input['form_config'] ?? null,
                config: $input['config'] ?? null,
                is_active: $input['is_active'] ?? null,
                is_published: $input['is_published'] ?? null,
                weight: isset($input['weight']) ? (float) $input['weight'] : null,
                parent_id: isset($input['parent_id']) ? (int) $input['parent_id'] : null,
                status: $input['status'] ?? null,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $companyAction = CompanyAction::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $companyAction->softDelete();
    }
}
