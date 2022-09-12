<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Apps\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Apps\Actions\CreateAppsAction;
use Exception;

final class CreateApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver
        $dto = AppsPostData::fromArray($request['input']);
        $action = new  CreateAppsAction($dto);
        return $action->execute();
    }
}
