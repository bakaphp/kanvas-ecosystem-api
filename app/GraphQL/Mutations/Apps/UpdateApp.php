<?php
namespace App\GraphQL\Mutations\Apps;

use Kanvas\Apps\Apps\DataTransferObject\AppsPutData;
use Kanvas\Apps\Apps\Actions\UpdateAppsAction;

final class UpdateApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver\
        $input = $request['input'];
        $dto = AppsPutData::fromArray([
            'name' => $input['name'],
            'url' => $input['url'],
            'description' => $input['description'],
            'domain' => $input['domain'],
            'is_actived' => $input['is_actived'],
            'ecosystem_auth' => $input['ecosystem_auth'],
            'payments_active' => $input['payments_active'],
            'is_public' => $input['is_public'],
            'domain_based' => $input['domain_based']
        ]);
        $action = new UpdateAppsAction($dto);
        return $action->execute($request['id']);
    }
}
