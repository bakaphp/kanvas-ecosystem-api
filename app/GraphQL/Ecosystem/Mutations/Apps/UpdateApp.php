<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\DataTransferObject\AppsPutData;
use Kanvas\Apps\Actions\UpdateAppsAction;

final class UpdateApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver\
        $dto = AppsPutData::fromArray($request['input']);
        $action = new UpdateAppsAction($dto);
        return $action->execute($request['id']);
    }
}
