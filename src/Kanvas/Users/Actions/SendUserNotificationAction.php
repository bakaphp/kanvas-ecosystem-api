<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Services\UserRoleNotificationService;

use function Sentry\captureException;

/**
 * @todo move this notification to b2b domain
 */
class SendUserNotificationAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies|CompanyInterface $company,
        protected Users $user,
    ) {
    }

    public function execute(
        string $templateName,
        array $data = []
    ): void {
        if ($b2bCompany = B2BConfigurationService::getConfiguredB2BCompany($this->app, $this->company)) {
            $notification = new Notification(
                $this->user,
                $this->company->toArray(),
            );
            $notification->setTemplateName($templateName);
            $notification->setData($data);
            $notification->setType(NotificationTypes::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'key' => $b2bCompany::class,
                'name' => Str::simpleSlug($b2bCompany::class),
                'system_modules_id' => SystemModulesRepository::getByModelName($b2bCompany::class, $this->app)->getId(),
                'is_deleted' => 0,
            ], [
                'template' => $templateName,
            ])->name);

            $notification->channels = [];
            if ($this->app->get(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value)) {
                $notification->channels = ['mail'];
            }

            try {
                UserRoleNotificationService::notify(
                    RolesEnums::OWNER->value,
                    $notification,
                    $this->app
                );
            } catch (Exception $e) {
                captureException($e);
            }
        }
    }
}
