<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Actions\UpdateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\DataTransferObject\AppSettingsInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Repositories\UsersRepository;

final class ManageApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function create($_, array $request)
    {
        // TODO implement the resolver
        $dto = AppInput::from($request['input']);
        $action = new  CreateAppsAction($dto, auth()->user());
        return $action->execute();
    }

    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function update($_, array $request)
    {
        // TODO implement the resolver\
        $dto = AppInput::from($request['input']);
        $action = new UpdateAppsAction($dto, auth()->user());
        return $action->execute($request['id']);
    }

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

    /**
     * Save app setting
     *
     * @param mixed $root
     * @param array $req
     * @return mixed
     */
    public function saveSettings(mixed $root, array $req) : mixed
    {
        $app = AppsRepository::findFirstByKey($req['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $appSetting = AppSettingsInput::from($req['input']);

        $app->set($appSetting->name, $appSetting->value);

        return $app->get($appSetting->name);
    }
}
