<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Repositories\UsersRepository;

final class DeleteApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function delete($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //@todo only app creator can delete app
        $app->softDelete();

        return $app;
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return AttributeModel
     */
    public function restore(mixed $root, array $req) : Apps
    {
        $app = Apps::where('key', $req['id'])->firstOrFail();

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //@todo only app creator can delete app
        $app->is_deleted = StateEnums::NO->getValue();
        $app->is_actived = StateEnums::YES->getValue();
        $app->saveOrFail();

        return $app;
    }
}
