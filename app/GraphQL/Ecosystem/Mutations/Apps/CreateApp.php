<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\DataTransferObject\AppsPostData;
use Kanvas\Apps\Actions\CreateAppsAction;
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
        $dto = AppsPostData::from($request['input']);
        $action = new  CreateAppsAction($dto);
        return $action->execute(auth()->user());
    }
}
