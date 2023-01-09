<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Users\Repositories\UsersRepository;

final class DeleteApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::belongsToThisApp(auth()->user(), $app);

        //@todo only app creator can delete app
        $app->softDelete();

        return $app;
    }
}
