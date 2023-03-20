<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Templates\Actions\CreateTemplate;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Actions\UpdateAppsAction;
use Kanvas\Apps\DataTransferObject\AppSettingsInput;
use Kanvas\Apps\Models\Apps;

class AppManagementMutation
{
    /**
     * activeApp
     *
     * @param  mixed $root
     * @param  array $request
     * @return void
     */
    public function activeApp(mixed $root, array $request)
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

    /**
     * assignCompanyToApp
     *
     * @param  mixed $root
     * @param  array $req
     * @return void
     */
    public function assignCompanyToApp(mixed $root, array $request)
    {
        $id = $request['id'];
        $companyId = $request['companyId'];

        $app = AppsRepository::findFirstByKey($id);
        $company = CompaniesRepository::getByUuid($companyId);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);
        UsersRepository::belongsToCompany(auth()->user(), $company);

        //$action = new  CreateAppsAction($dto);
        $app->associateCompany($company);

        return $company;
    }

    /**
     * removeCompanyToApp
     * @param  null  $_
     * @param  array{}  $args
     */
    public function removeCompanyToApp($_, array $request): Companies
    {
        $id = $request['id'];
        $companyId = $request['companyId'];

        $app = AppsRepository::findFirstByKey($id);
        $company = CompaniesRepository::getByUuid($companyId);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //if they are super user no need to verify if they belong to the company
        //UsersRepository::belongsToCompany(auth()->user(), $company);

        //$action = new  CreateAppsAction($dto);
        $app->associateCompany($company)->delete();

        return $company;
    }

    /**
     * createAppTemplate
     * @param  null  $_
     * @param  array{}  $args
     */
    public function createAppTemplate($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $createTemplate = new CreateTemplate(
            new TemplateInput(
                $app,
                $request['input']['name'],
                $request['input']['template'],
                null,
                auth()->user()
            )
        );

        return $createTemplate->execute();
    }

    /**
     * @param null $_
     * @param array{} $args
     */
    public function createApp($_, array $request)
    {
        // TODO implement the resolver
        $dto = AppInput::from($request['input']);
        $action = new CreateAppsAction($dto, auth()->user());

        return $action->execute();
    }

    /**
     * @param null $_
     * @param array{} $args
     */
    public function updateApp($_, array $request)
    {
        // TODO implement the resolver\
        $dto = AppInput::from($request['input']);
        $action = new UpdateAppsAction($dto, auth()->user());

        return $action->execute($request['id']);
    }

    /**
     * @param null $_
     * @param array{} $args
     */
    public function deleteApp($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        // @todo only app creator can delete app
        $app->softDelete();

        return $app;
    }

    /**
     * restoreApp.
     *
     * @return Apps
     */
    public function restoreApp(mixed $root, array $req): Apps
    {
        $app = Apps::where('key', $req['id'])->firstOrFail();

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        // @todo only app creator can delete app
        $app->is_deleted = StateEnums::NO->getValue();
        $app->is_actived = StateEnums::YES->getValue();
        $app->saveOrFail();

        return $app;
    }

    /**
     * Save app setting.
     */
    public function saveSettings(mixed $root, array $req): mixed
    {
        $app = AppsRepository::findFirstByKey($req['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $appSetting = AppSettingsInput::from($req['input']);

        $app->set($appSetting->name, $appSetting->value);

        return $app->get($appSetting->name);
    }
}
