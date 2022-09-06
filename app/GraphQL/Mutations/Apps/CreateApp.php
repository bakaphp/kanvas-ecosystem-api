<?php
namespace App\GraphQL\Mutations\Apps;

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
        $dto = AppsPostData::fromArray([
            'name' => $request['input']['name'],
            'url' => $request['input']['url'],
            'description' => $request['input']['description'],
            'domain' => $request['input']['domain'],
            'is_actived' => $request['input']['is_actived'],
            'ecosystem_auth' => $request['input']['ecosystem_auth'],
            'payments_active' => $request['input']['payments_active'],
            'is_public' => $request['input']['is_public'],
            'domain_based' => $request['input']['domain_based']
        ]);
        $action = new  CreateAppsAction($dto);
        return $action->execute();
    }
}
