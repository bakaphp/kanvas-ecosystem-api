<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Status;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Status\Models\Status as StatusModel;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Languages\DataTransferObject\Translate;
use Kanvas\Languages\Services\Translation as TranslationService;

class StatusMutation
{
    /**
     * create.
     *
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

        $status = StatusRepository::getById((int) $req['id'], $company);
        $statusTranslateDto = Translate::fromMultiple($req['input'], $company);

        $response = TranslationService::updateTranslation(
            model: $status,
            dto: $statusTranslateDto,
            code: $req['code']
        );

        return $response;
    }
}
