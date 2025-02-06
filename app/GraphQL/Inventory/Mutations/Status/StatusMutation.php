<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Status;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Status\DataTransferObject\Translate as StatusTranslateDto;
use Kanvas\Inventory\Status\Models\Status as StatusModel;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Languages\Models\Languages;

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
        if (auth()->user()->isAppOwner() && isset($request['input']['company_id'])) {
            $company = Companies::getById($request['input']['company_id']);
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

    /**
     * update.
     */
    public function updateStatusTranslation(mixed $root, array $req): StatusModel
    {
        $company = auth()->user()->getCurrentCompany();
        $language = Languages::getByCode($req['code']);
        $input = $req['input'];

        $status = StatusRepository::getById((int) $req['id'], $company);
        $statusTranslateDto = new StatusTranslateDto(name: $input['name']);

        foreach ($statusTranslateDto->toArray() as $key => $value) {
            $status->setTranslation($key, $language->code, $value);
            $status->save();
        }

        return $status;
    }
}
