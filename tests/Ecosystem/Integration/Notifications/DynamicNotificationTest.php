<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Actions\CreateNotificationTypeAction;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\DataTransferObject\NotificationType;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationChannel;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;

final class DynamicNotificationTest extends TestCase
{
    public function testDynamicNotificationTypeNotification()
    {
        $user = auth()->user();
        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification',
                'template' => '<html><body>Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values</body></html>',
                ])
        );
        $template = $createParentTemplate->execute();

        $createPushTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification-push',
                'template' => 'Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values',
                ])
        );
        $pushTemplate = $createPushTemplate->execute();

        $notificationType = (new CreateNotificationTypeAction(
            new NotificationType(
                app(Apps::class),
                $user,
                'test-notification',
                'test notification',
                $template
            )
        ))->execute();

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::MAIL->value),
            $template
        );

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::PUSH->value),
            $pushTemplate
        );

        $dynamicNotification = new DynamicKanvasNotification(
            $notificationType,
            ['dynamic' => 'dynamic ' . time()]
        );

        Notification::fake();

        $user->notify($dynamicNotification);

        Notification::assertSentTo(
            $user,
            DynamicKanvasNotification::class
        );
    }

    /**
     * Passing the 'database' slug must resolve to the KanvasDatabase channel and,
     * when the notification is delivered, persist a row in the notifications table.
     */
    public function testDatabaseChannelBySlugPersistsNotification(): void
    {
        $this->assertSame(
            KanvasDatabase::class,
            NotificationChannelEnum::getNotificationChannelBySlug('database')
        );

        $user = auth()->user();
        $app = app(Apps::class);

        $template = new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'test-notification-database-channel',
                'template' => '<html><body>database channel {{ isset($dynamic) ? $dynamic : \'default\' }}</body></html>',
            ])
        )->execute();

        $notificationType = new CreateNotificationTypeAction(
            new NotificationType(
                $app,
                $user,
                'test-notification-database-channel',
                'test notification database channel',
                $template
            )
        )->execute();

        $notification = new DynamicKanvasNotification(
            $notificationType,
            ['dynamic' => 'value ' . time()]
        );
        $notification->setChannels('database');

        $this->assertContains(KanvasDatabase::class, $notification->via($user));

        $countBefore = Notifications::where('users_id', $user->getId())
            ->where('notification_type_id', $notificationType->getId())
            ->count();

        new KanvasDatabase()->send($user, $notification);

        $this->assertSame(
            $countBefore + 1,
            Notifications::where('users_id', $user->getId())
                ->where('notification_type_id', $notificationType->getId())
                ->count()
        );
    }

    /**
     * Regression for KANVAS-ECOSYSTEM-5PW: a Blank notification whose `data['message']`
     * holds a model object (not a pre-rendered string) must still persist on the
     * database channel. message() is the only consumer of data['message'] and is
     * typed `: string`, so a model with no __toString previously threw a TypeError
     * inside toKanvasDatabase(), killing the DB channel while mail/sms/push survived.
     */
    public function testDatabaseChannelPersistsWhenDataMessageIsModelObject(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'agent-reply-manager-notification',
                'template' => '<html><body>Agent replied to {{ $message->title ?? \'lead\' }}</body></html>',
            ])
        )->execute();

        $notification = new Blank(
            templateName: 'agent-reply-manager-notification',
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['database'],
            entity: $lead
        );

        $this->assertContains(KanvasDatabase::class, $notification->via($user));

        $countBefore = Notifications::where('users_id', $user->getId())
            ->where('entity_id', $lead->getId())
            ->count();

        new KanvasDatabase()->send($user, $notification);

        $this->assertSame(
            $countBefore + 1,
            Notifications::where('users_id', $user->getId())
                ->where('entity_id', $lead->getId())
                ->count()
        );
    }

    /** Pins the no-override default so a future message() refactor can't silently regress it. */
    public function testDatabaseContentFallsBackToEmailBodyWhenNoDatabaseTemplateSet(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $emailMarker = 'EMAIL-BODY-MARKER-' . time();
        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'baseline-email-only-notification',
                'template' => '<html><body>' . $emailMarker . '</body></html>',
            ])
        )->execute();

        $notification = new Blank(
            templateName: 'baseline-email-only-notification',
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['database'],
            entity: $lead
        );

        new KanvasDatabase()->send($user, $notification);

        $stored = Notifications::where('users_id', $user->getId())
            ->where('entity_id', $lead->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($stored);
        $this->assertStringContainsString($emailMarker, (string) $stored->content);
    }

    public function testDatabaseTemplateNameOverridesEmailContentInStoredRow(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'agent-manager-notification-email',
                'template' => '<html><body><h1>This is the long email body that must NOT land in notifications.content</h1></body></html>',
            ])
        )->execute();

        $databaseBlurb = 'Short DB blurb ' . time();
        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'agent-manager-notification-database',
                'template' => $databaseBlurb,
            ])
        )->execute();

        $notification = new Blank(
            templateName: 'agent-manager-notification-email',
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['database'],
            entity: $lead
        );
        $notification->setDatabaseTemplateName('agent-manager-notification-database');

        new KanvasDatabase()->send($user, $notification);

        $stored = Notifications::where('users_id', $user->getId())
            ->where('entity_id', $lead->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($databaseBlurb, $stored->content);
        $this->assertStringNotContainsString('long email body', (string) $stored->content);
    }

    private function makeGateNotification(): Blank
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'notification-gate-test',
                'template' => '<html><body>gate {{ $message->id ?? \'x\' }}</body></html>',
            ])
        )->execute();

        return new Blank(
            templateName: 'notification-gate-test',
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['database'],
            entity: $lead
        );
    }

    private function appMembership(): UsersAssociatedApps
    {
        return UsersAssociatedApps::where('users_id', auth()->user()->getId())
            ->where('apps_id', app(Apps::class)->getId())
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->firstOrFail();
    }

    /** Baseline: a user active in this app still resolves the requested channel. */
    public function testUserActiveInAppResolvesChannels(): void
    {
        $membership = $this->appMembership();
        $original = $membership->only(['is_active', 'is_deleted']);

        try {
            $membership->forceFill(['is_active' => 1, 'is_deleted' => 0])->saveOrFail();
            $this->assertContains(KanvasDatabase::class, $this->makeGateNotification()->via(auth()->user()));
        } finally {
            $membership->forceFill($original)->saveOrFail();
        }
    }

    /** Disabled for THIS app (is_active = 0) must resolve zero channels, even if active elsewhere. */
    public function testUserDisabledInAppResolvesNoChannels(): void
    {
        $membership = $this->appMembership();
        $original = $membership->only(['is_active', 'is_deleted']);

        try {
            $membership->forceFill(['is_active' => 0])->saveOrFail();
            $this->assertEmpty($this->makeGateNotification()->via(auth()->user()));
        } finally {
            $membership->forceFill($original)->saveOrFail();
        }
    }

    /** Soft-deleted membership for this app must resolve zero channels. */
    public function testUserDeletedFromAppResolvesNoChannels(): void
    {
        $membership = $this->appMembership();
        $original = $membership->only(['is_active', 'is_deleted']);

        try {
            $membership->forceFill(['is_active' => 1, 'is_deleted' => 1])->saveOrFail();
            $this->assertEmpty($this->makeGateNotification()->via(auth()->user()));
        } finally {
            $membership->forceFill($original)->saveOrFail();
        }
    }

    /** A globally deleted user resolves zero channels regardless of app membership. */
    public function testGloballyDeletedUserResolvesNoChannels(): void
    {
        $user = auth()->user();
        $original = $user->is_deleted;

        try {
            $user->is_deleted = 1;
            $this->assertEmpty($this->makeGateNotification()->via($user));
        } finally {
            $user->is_deleted = $original;
        }
    }
}
