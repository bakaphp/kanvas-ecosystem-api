<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * @todo dont like where this place here , but for now, dont know where else to put it
 */
class AppUsersNotificationByRoleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Model $entity,
        protected array $params
    ) {
    }

    public function execute(): array
    {
        $filterByCompany = $this->params['filterByCompany'] ?? false;
        $company = $this->params['company'] ?? null;
        $role = $this->params['role'] ?? RolesEnums::USER->value;
        $appUsers = UsersRepository::getAppUserByRole($this->app, $role);
        $notificationVias = $this->params['notificationVia'] ?? [NotificationChannelEnum::getNotificationChannelBySlug('mail')];
        $this->params['entity'] = $this->entity;
        $this->params['app'] = $this->app;

        if ($filterByCompany && $company) {
            $appUsers->where('users_associated_apps.companies_id', $company->getId());
        }

        foreach ($notificationVias as $via) {
            $vias[] = NotificationChannelEnum::getNotificationChannelBySlug($via);
        }

        if (empty($this->params['template_name'])) {
            return [
                'error' => 'Template name is required',
            ];
        }

        $fromUserId = $this->params['from_user_id'] ?? $this->app->get(AppSettingsEnums::NOTIFICATION_FROM_USER_ID->getValue());
        if (empty($fromUserId)) {
            return [
                'error' => 'From user id is required',
            ];
        }

        $vias[] = NotificationChannelEnum::getNotificationChannelBySlug('database');
        $notification = new Blank(
            $this->params['template_name'],
            $this->params,
            $vias,
            $this->entity
        );

        $user = Users::getById($fromUserId);
        $notification->setFromUser($user);

        $totalNotificationSent = 0;
        $totalSkipped = 0;
        foreach ($appUsers->get() as $userApp) {
            //@todo since all notification type are base on blank, this can cause issues of diff notification types not being sent
            if ($userApp->hasBeenNotified($this->entity, $notification->getType())) {
                $totalSkipped++;

                continue;
            }
            $userApp->notify($notification);
            $totalNotificationSent++;
        }

        return [
            'totalNotificationSent' => $totalNotificationSent,
            'totalSkipped' => $totalSkipped,
            'notification' => $this->params,
        ];
    }
}
