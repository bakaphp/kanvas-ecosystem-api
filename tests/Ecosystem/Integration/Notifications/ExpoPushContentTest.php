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
    private function makeNotification(string $pushMessageTemplate): Blank
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
            templateName: 'expo-content-test-body',
            data: [
                'message' => $lead,
                'company' => $company,
                'app' => $app,
                'user' => $user,
            ],
            via: ['expo'],
            entity: $lead
        );

        $notification->setPushTitleTemplateName('expo-content-test-title');
        $notification->setPushMessageTemplateName($pushMessageTemplate);

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
