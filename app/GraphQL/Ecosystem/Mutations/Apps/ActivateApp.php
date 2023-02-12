<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Repositories\UsersRepository;

final class ActivateApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        $id = $request['id'];

        $app = AppsRepository::findFirstByKey($id);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //$action = new  CreateAppsAction($dto);
        $app->is_actived = StateEnums::YES->getValue();
        $app->saveOrFail();

        return $app;
    }

    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function deActive($_, array $request)
    {
        $id = $request['id'];

        $app = AppsRepository::findFirstByKey($id);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //$action = new  CreateAppsAction($dto);
        $app->is_actived = StateEnums::NO->getValue();
        $app->saveOrFail();

        return $app;
    }
}
