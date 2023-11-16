<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Status;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Status\Models\Status as StatusModel;
use Kanvas\Inventory\Status\Repositories\StatusRepository;

class StatusMutation
{
    /**
     * create.
     *
     * @param  mixed $rootValue
     * @param  array $args
     *
     * @return StatusModel
     */
    public function create(mixed $rootValue, array $request): StatusModel
    {
        if (auth()->user()->isAppOwner() && isset($req['input']['company_id'])) {
            $company = Companies::getById($req['input']['company_id']);
        } else {
            $company = auth()->user()->getCurrentCompany();
        }

        $dto = StatusDto::viaRequest($request['input'], $company);
        $status = (new CreateStatusAction($dto, auth()->user()))->execute();

        return $status;
    }

    /**
     * update.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return StatusModel
     */
    public function update(mixed $rootValue, array $request): StatusModel
    {
        $id = $request['id'];
        $data = $request['input'];
        $status = StatusRepository::getById((int) $id, auth()->user()->getCurrentCompany());
        $status->update($data);

        return $status;
    }

    /**
     * delete.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function delete(mixed $rootValue, array $request): bool
    {
        $id = $request['id'];
        $status = StatusRepository::getById((int) $id, auth()->user()->getCurrentCompany());

        return $status->delete();
    }
}
