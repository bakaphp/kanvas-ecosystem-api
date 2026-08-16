<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Notifications;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use NotificationChannels\Expo\ExpoChannel;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-5VC: ExpoMessage::body() throws on an empty string,
 * so a push template that renders to nothing killed the queued notification job.
 */
final class ExpoPushContentTest extends TestCase
{
    private function makeNotification(string $pushMessageTemplate, bool $withPushTemplates = true): Blank
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
                'name' => 'expo-content-test-title',
                'template' => 'Expo title',
            ])
        )->execute();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'expo-content-test-body',
                'template' => 'Expo body for lead {{ $message->id ?? \'x\' }}',
            ])
        )->execute();

        $notification = new Blank(
            templateName: $withPushTemplates ? 'expo-content-test-body' : $pushMessageTemplate,
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['expo'],
            entity: $lead
        );

        if ($withPushTemplates) {
            $notification->setPushTitleTemplateName('expo-content-test-title');
            $notification->setPushMessageTemplateName($pushMessageTemplate);
        }

        return $notification;
    }

    public function testExpoChannelIsSkippedWhenMessageRendersEmpty(): void
    {
        $notification = $this->makeNotification('expo-content-test-missing-body-template');

        $this->assertFalse($notification->shouldSend(auth()->user(), ExpoChannel::class));
    }

    public function testExpoChannelIsSentWhenMessageHasContent(): void
    {
        $notification = $this->makeNotification('expo-content-test-body');
        $user = auth()->user();

        $this->assertTrue($notification->shouldSend($user, ExpoChannel::class));
        $this->assertStringContainsString('Expo body for lead', $notification->toExpo($user)->toArray()['body']);
    }

    /**
     * The split-template path swallows a missing template (tryRenderTemplate), but a notification
     * that renders one whole push template throws ModelNotFoundException. shouldSend() is a
     * predicate — that throw used to escape it and kill the entire queued notification, taking the
     * mail/database channels down with a push template nobody configured.
     */
    public function testExpoChannelIsSkippedWhenThePushTemplateDoesNotExist(): void
    {
        // No push title/message setters — so resolvePushTemplateName() falls back to the single
        // template name, which has no row and makes the render throw.
        $notification = $this->makeNotification(
            'expo-content-test-no-such-template',
            withPushTemplates: false
        );

        $this->assertFalse($notification->shouldSend(auth()->user(), ExpoChannel::class));
        $this->assertTrue($notification->shouldSend(auth()->user(), KanvasDatabase::class));
    }

    public function testOtherChannelsAreUnaffectedByEmptyPushContent(): void
    {
        $notification = $this->makeNotification('expo-content-test-missing-body-template');

        $this->assertTrue($notification->shouldSend(auth()->user(), KanvasDatabase::class));
    }

    public function testToExpoStillGuardsAgainstEmptyMessage(): void
    {
        $notification = $this->makeNotification('expo-content-test-missing-body-template');

        $this->expectException(ValidationException::class);
        $notification->toExpo(auth()->user());
    }
}
