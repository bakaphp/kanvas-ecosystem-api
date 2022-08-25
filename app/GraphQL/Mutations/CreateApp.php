<?php
namespace App\GraphQL\Mutations;

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
        $request = $request['input'];
        $data = [
            'name' => $request['name'],
            'url' => $request['url'],
            'description' => $request['description'],
            'domain' => $request['domain'],
            'is_actived' => $request['is_actived'],
            'ecosystem_auth' => $request['ecosystem_auth'],
            'payments_active' => $request['payments_active'],
            'is_public' => $request['is_public'],
            'domain_based' => $request['domain_based']
        ];
        $dto = AppsPostData::fromArray($data);
        $action = new  CreateAppsAction($dto);
       return $action->execute();
    }
}
