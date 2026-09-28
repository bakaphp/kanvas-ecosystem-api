<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Actions;

use Kanvas\ActionEngine\Actions\Actions\CreateActionAction;
use Kanvas\ActionEngine\Actions\Actions\UpdateActionAction;
use Kanvas\ActionEngine\Actions\DataTransferObject\Action as ActionData;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;

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
        return new UpdateActionAction(
            $this->getWritableAction((int) $request['id']),
            ActionData::from($request['input']),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $action = $this->getWritableAction((int) $request['id']);

        if ($action->companyActions()->where('is_deleted', 0)->exists()) {
            throw new ValidationException('Cannot delete action that is in use by company actions.');
        }

        return $action->softDelete();
    }

    /**
     * Actions are pegged to the creating app, so the CRUD only owns its own app's rows; apps_id 0 is a
     * read-only platform catalog the list query unions in. Keep the fall-through order: another app's
     * action must report not-found, or the read-only branch reveals which ids exist elsewhere.
     */
    private function getWritableAction(int $id): Action
    {
        try {
            /** @var Action $action */
            $action = Action::getById($id, app(Apps::class));

            return $action;
        } catch (ModelNotFoundException $e) {
            if (Action::query()->where('id', $id)->fromPublicApp()->notDeleted()->exists()) {
                throw new ValidationException('Action ' . $id . ' is a global action and is read-only.');
            }

            throw $e;
        }
    }
}
