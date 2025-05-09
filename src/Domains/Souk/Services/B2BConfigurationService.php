<?php

declare(strict_types=1);

namespace Kanvas\Souk\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Services\UserRoleNotificationService;

class B2BConfigurationService
{
    public static function getConfiguredB2BCompany(
        AppInterface $app,
        Companies|CompanyInterface $company
    ): Companies|CompanyInterface {
        if ($app->get(ConfigurationEnum::USE_B2B_COMPANY_GROUP->value)) {
            $b2bGlobalCompanyId = $app->get(ConfigurationEnum::B2B_GLOBAL_COMPANY->value);
            $userCompanyApp = UserCompanyApps::where('companies_id', $b2bGlobalCompanyId)
                             ->where('apps_id', $app->getId())
                             ->first();
            if ($userCompanyApp) {
                $company = Companies::getById($b2bGlobalCompanyId);
            }
        }

        return $company;
    }

    public static function hasGlobalCompany(
        AppInterface $app,
        string $groupName = 'USE_B2B_COMPANY_GROUP',
        string $companyIdKey = 'B2B_GLOBAL_COMPANY'
    ): bool {
        if ($app->get($groupName)) {
            if (UserCompanyApps::where('companies_id', $app->get($companyIdKey))->where('apps_id', $app->getId())->first()) {
                return true;
            }
        }

        return false;
    }

    public static function sendNotificationToUsers(AppInterface $app, Companies $company, string $templateName, Users $user, array $data = [])
    {
        if ($b2bCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $company)) {
            $notification = new Notification(
                $user,
                $company->toArray(),
            );
            $notification->setTemplateName($templateName);
            $notification->setData($data);
            $notification->setType(NotificationTypes::firstOrCreate([
                'apps_id' => $app->getId(),
                'key' => $b2bCompany::class,
                'name' => Str::simpleSlug($b2bCompany::class),
                'system_modules_id' => SystemModulesRepository::getByModelName($b2bCompany::class, $app)->getId(),
                'is_deleted' => 0,
            ], [
                'template' => $templateName,
            ])->name);
            $notification->channels = [NotificationChannelEnum::DATABASE->value, NotificationChannelEnum::MAIL->value];
            UserRoleNotificationService::notify(
                RolesEnums::OWNER->value,
                $notification,
                $app
            );
        }
    }
}
