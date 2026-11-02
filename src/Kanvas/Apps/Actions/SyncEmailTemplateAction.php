<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\File;
use Kanvas\ActionEngine\Engagements\Enums\NotificationTemplateEnum as EngagementsEnumsNotificationTemplateEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum as LeadsEnumsEmailTemplateEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpEmailTemplateEnum;
use Kanvas\Intelligence\Notifications\Enums\NotificationTemplateEnum as IntelligenceNotificationTemplateEnum;
use Kanvas\Inventory\Products\Enums\EmailTemplateEnum as ProductsEnumsEmailTemplateEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Notifications\Templates\EmailVerification;
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
                'template' => File::get(resource_path('views/emails/defaultTemplate.twig')),
            ], [
                'name' => 'user-email-update',
                'template' => File::get(resource_path('views/emails/defaultTemplate.twig')),
            ], [
                'name' => EmailTemplateEnum::USER_INVITE->value,
                'template' => File::get(resource_path('views/emails/userInvite.twig')),
            ], [
                'name' => EmailTemplateEnum::ADMIN_USER_INVITE->value,
                'template' => File::get(resource_path('views/emails/adminUserInvite.twig')),
            ], [
                'name' => EmailTemplateEnum::ADMIN_USER_INVITE_EXISTING_USER->value,
                'template' => File::get(resource_path('views/emails/adminUserInviteAlreadyExist.twig')),
            ], [
                'name' => EmailTemplateEnum::CHANGE_PASSWORD->value,
                'template' => File::get(resource_path('views/emails/passwordUpdated.twig')),
            ], [
                'name' => EmailTemplateEnum::RESET_PASSWORD->value,
                'template' => File::get(resource_path('views/emails/resetPassword.twig')),
            ], [
                'name' => EmailTemplateEnum::WELCOME->value,
                'template' => File::get(resource_path('views/emails/welcome.twig')),
            ], [
                'name' => EmailTemplateEnum::EMAIL_VERIFICATION->value,
                'template' => File::get(resource_path('views/emails/emailVerification.twig')),
            ], [
                'name' => PushNotificationTemplateEnum::DEFAULT->value,
                'template' => File::get(resource_path('views/emails/pushNotification.twig')),
            ], [
                'name' => EnumsEmailTemplateEnum::NEW_ORDER->value,
                'template' => File::get(resource_path('views/emails/newOrder.twig')),
            ], [
                'name' => EnumsEmailTemplateEnum::NEW_ORDER_STORE_OWNER->value,
                'template' => File::get(resource_path('views/emails/newOrderStoreOwner.twig')),
            ], [
                'name' => LeadsEnumsEmailTemplateEnum::NEW_LEAD->value,
                'template' => File::get(resource_path('views/emails/newLead.twig')),
            ], [
                'name' => LeadsEnumsEmailTemplateEnum::NEW_LEAD_COMPANY_ADMIN->value,
                'template' => File::get(resource_path('views/emails/newLeadCompany.twig')),
            ], [
                'name' => LeadsEnumsEmailTemplateEnum::DAILY_LEADS_DIGEST->value,
                'template' => File::get(resource_path('views/emails/dailyLeadsDigest.twig')),
            ],[
                'name' => NotificationTemplateEnum::PUSH_NEW_FOLLOWER->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.twig')),
            ],[
                'name' => EnumsNotificationTemplateEnum::PUSH_NEW_MESSAGE->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.twig')),
            ],[
                'name' => EnumsNotificationTemplateEnum::PUSH_NEW_INTERACTION_MESSAGE->value,
                'template' => File::get(resource_path('views/emails/newPushNotification.twig')),
            ],[
                'name' => ProductsEnumsEmailTemplateEnum::LOW_STOCK->value,
                'template' => File::get(resource_path('views/emails/lowStock.twig')),
            ],[
                'name' => EngagementsEnumsNotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED->value,
                'template' => File::get(resource_path('views/push/engagement-status-changed.twig')),
            ], [
                'name' => EngagementsEnumsNotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED_DATABASE->value,
                'template' => File::get(resource_path('views/push/engagement-status-changed-db.twig')),
            ], [
                'name' => EnumsNotificationTemplateEnum::EMAIL_USER_MENTION->value,
                'template' => File::get(resource_path('views/emails/userMentioned.twig')),
            ], [
                'name' => IntelligenceNotificationTemplateEnum::EMAIL_AGENT_MENTION_REPLY->value,
                'template' => File::get(resource_path('views/emails/agentMentionReply.twig')),
            ], [
                'name' => IntelligenceNotificationTemplateEnum::PUSH_AGENT_MENTION_REPLY->value,
                'template' => File::get(resource_path('views/push/agent-mention-reply.twig')),
            ], [
                'name' => IntelligenceNotificationTemplateEnum::DATABASE_AGENT_MENTION_REPLY->value,
                'template' => File::get(resource_path('views/push/agent-mention-reply-db.twig')),
            ], [
                'name' => FollowUpEmailTemplateEnum::DAILY_SUMMARY->value,
                'template' => File::get(resource_path('views/emails/leadFollowUpDailySummary.twig')),
            ],
        ];

        $dto = TemplateInput::from([
            'app' => $this->app,
            'name' => $templates[0]['name'],
            'template' => $templates[0]['template'],
            'user' => $this->user,
            'is_system' => true,
        ]);

        $action = new CreateTemplateAction($dto);
        $parent = $action->execute();

        //remove first
        array_shift($templates);

        foreach ($templates as $template) {
            $dto = TemplateInput::from([
                'app' => $this->app,
                'name' => $template['name'],
                'template' => $template['template'],
                'user' => $this->user,
                'is_system' => true,
            ]);
            $action = new CreateTemplateAction($dto);
            // push + database templates render to raw JSON / plain text — wrapping them in the
            // HTML email parent corrupts the payload (invalid push JSON, HTML-polluted db content).
            $noParentTemplates = [
                PushNotificationTemplateEnum::DEFAULT->value,
                'user-email-update',
                EngagementsEnumsNotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED->value,
                EngagementsEnumsNotificationTemplateEnum::ENGAGEMENT_STATUS_CHANGED_DATABASE->value,
                IntelligenceNotificationTemplateEnum::PUSH_AGENT_MENTION_REPLY->value,
                IntelligenceNotificationTemplateEnum::DATABASE_AGENT_MENTION_REPLY->value,
            ];

            $action->execute(
                (! in_array($template['name'], $noParentTemplates) ? $parent : null),
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
            EmailTemplateEnum::EMAIL_VERIFICATION->value => EmailVerification::class,
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
