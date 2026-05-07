<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Actions;

use Kanvas\ActionEngine\Actions\Actions\CreateActionAction;
use Kanvas\ActionEngine\Actions\Actions\UpdateActionAction;
use Kanvas\ActionEngine\Actions\DataTransferObject\Action as ActionData;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\Apps\Models\Apps;

class ActionMutation
{
    public function create(mixed $rootValue, array $request): Action
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return new CreateActionAction(
            ActionData::from($request['input']),
            $user,
            $app,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Action
    {
        $app = app(Apps::class);
        $action = Action::getById((int) $request['id'], $app);

        return new UpdateActionAction(
            $action,
            ActionData::from($request['input']),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $action = Action::getById((int) $request['id'], $app);

        if ($action->companyActions()->where('is_deleted', 0)->exists()) {
            throw new \Exception('Cannot delete action that is in use by company actions.');
        }

        return $action->softDelete();
    }
}
