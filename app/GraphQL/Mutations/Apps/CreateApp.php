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
        $request = $request['input'];
        $dto = AppsPostData::fromArray([
            'name' => $request['name'],
            'url' => $request['url'],
            'description' => $request['description'],
            'domain' => $request['domain'],
            'is_actived' => $request['is_actived'],
            'ecosystem_auth' => $request['ecosystem_auth'],
            'payments_active' => $request['payments_active'],
            'is_public' => $request['is_public'],
            'domain_based' => $request['domain_based']
        ]);
        $action = new  CreateAppsAction($dto);
        return $action->execute();
    }
}
