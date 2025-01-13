<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\File;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum as LeadsEnumsEmailTemplateEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Notifications\Templates\Invite;
use Kanvas\Notifications\Templates\ResetPassword;
use Kanvas\Notifications\Templates\Welcome;
use Kanvas\Social\Follows\Enums\NotificationTemplateEnum;
use Kanvas\Social\Messages\Enums\NotificationTemplateEnum as EnumsNotificationTemplateEnum;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Templates\Enums\PushNotificationTemplateEnum;

class SyncEmailTemplateAction
{
    public function __construct(
        protected Apps $app,
        protected UserInterface $user
    ) {
    }

    public function execute(bool $overWrite = true): void
    {
        $this->createEmailTemplate($overWrite);
        $this->createNotificationTypes();
    }

    public function createEmailTemplate(bool $overWrite = true): void
    {
        $templates = [
            [
                'name' => EmailTemplateEnum::DEFAULT->value,
                'template' => File::get(resource_path('views/emails/defaultTemplate.blade.php')),
            ], [
                'name' => 'user-email-update',
                'template' => File::get(resource_path('views/emails/defaultTemplate.blade.php')),
            ], [
                'name' => EmailTemplateEnum::USER_INVITE->value,
                'template' => File::get(resource_path('views/emails/userInvite.blade.php')),
            ], [
                'name' => EmailTemplateEnum::ADMIN_USER_INVITE->value,
                'template' => File::get(resource_path('views/emails/adminUserInvite.blade.php')),
            ], [
                'name' => EmailTemplateEnum::ADMIN_USER_INVITE_EXISTING_USER->value,
                'template' => File::get(resource_path('views/emails/adminUserInviteAlreadyExist.blade.php')),
            ], [
                'name' => EmailTemplateEnum::CHANGE_PASSWORD->value,
                'template' => File::get(resource_path('views/emails/passwordUpdated.blade.php')),
            ], [
                'name' => EmailTemplateEnum::RESET_PASSWORD->value,
                'template' => File::get(resource_path('views/emails/resetPassword.blade.php')),
            ], [
                'name' => EmailTemplateEnum::WELCOME->value,
                'template' => File::get(resource_path('views/emails/welcome.blade.php')),
            ], [
                'name' => PushNotificationTemplateEnum::DEFAULT->value,
                'template' => File::get(resource_path('views/emails/pushNotification.blade.php')),
            ], [
                'name' => EnumsEmailTemplateEnum::NEW_ORDER->value,
                'template' => File::get(resource_path('views/emails/newOrder.blade.php')),
            ], [
                'name' => EnumsEmailTemplateEnum::NEW_ORDER_STORE_OWNER->value,
                'template' => File::get(resource_path('views/emails/newOrderStoreOwner.blade.php')),
            ], [
                'name' => LeadsEnumsEmailTemplateEnum::NEW_LEAD->value,
                'template' => File::get(resource_path('views/emails/newLead.blade.php')),
            ], [
                'name' => LeadsEnumsEmailTemplateEnum::NEW_LEAD_COMPANY_ADMIN->value,
                'template' => File::get(resource_path('views/emails/newLeadCompany.blade.php')),
            ],[
                'name' => NotificationTemplateEnum::PUSH_NEW_FOLLOWER->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.blade.php')),
            ],[
                'name' => EnumsNotificationTemplateEnum::PUSH_NEW_MESSAGE->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.blade.php')),
            ],[
                'name' => EnumsNotificationTemplateEnum::PUSH_NEW_INTERACTION_MESSAGE->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.blade.php')),
            ],
        ];

        $dto = new TemplateInput(
            $this->app,
            $templates[0]['name'],
            $templates[0]['template'],
            null,
            $this->user
        );

        $action = new CreateTemplateAction($dto);
        $parent = $action->execute();

        //remove first
        array_shift($templates);

        foreach ($templates as $template) {
            $dto = new TemplateInput(
                $this->app,
                $template['name'],
                $template['template'],
                null,
                $this->user
            );

            $action = new CreateTemplateAction($dto);
            $action->execute(
                (! in_array($template['name'], [PushNotificationTemplateEnum::DEFAULT->value, 'user-email-update']) ? $parent : null),
                $overWrite
            );
        }
    }

    public function createNotificationTypes(): void
    {
        $types = [
            EmailTemplateEnum::USER_INVITE->value => Invite::class,
            EmailTemplateEnum::RESET_PASSWORD->value => ResetPassword::class,
            EmailTemplateEnum::WELCOME->value => Welcome::class,
            EmailTemplateEnum::CHANGE_PASSWORD->value => ChangePasswordUserLogged::class,
            EmailTemplateEnum::BLANK->value => EmailTemplateEnum::BLANK->value,
        ];

        foreach ($types as $type => $value) {
            NotificationTypes::updateOrCreate([
               'apps_id' => $this->app->getId(),
               'key' => $value,
               'name' => Str::simpleSlug($value),
               'system_modules_id' => SystemModulesRepository::getByModelName($value, $this->app)->getId(),
               'is_deleted' => 0,
            ], [
               'template' => $type,
            ]);
        }
    }
}
