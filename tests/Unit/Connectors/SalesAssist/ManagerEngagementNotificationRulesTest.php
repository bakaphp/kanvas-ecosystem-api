<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\SalesAssist;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use NotificationChannels\Expo\ExpoChannel;
use Tests\TestCase;

class ManagerEngagementNotificationRulesTest extends TestCase
{
    public function testStructuredRulesUsePushAndSmsForFirstCustomerEngagement(): void
    {
        $lead = Lead::factory()->create();
        $lead->company->set(IntelligenceConfigurationEnum::AI_ENGAGEMENT_MANAGER_NOTIFICATION_RULES->value, [
            'mode' => 'first_push_sms_then_email',
            'first_engagement_channels' => ['push', 'sms'],
            'subsequent_engagement_channels' => ['email'],
        ]);

        $activity = new FakeSalesAssistNotificationActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertSame([
            OneSignalNotificationChannel::class,
            ExpoChannel::class,
            TwilioSmsChannel::class,
        ], $channels);
    }

    public function testStructuredRulesUseEmailForSubsequentCustomerEngagement(): void
    {
        $lead = Lead::factory()->create();
        $lead->company->set(IntelligenceConfigurationEnum::AI_ENGAGEMENT_MANAGER_NOTIFICATION_RULES->value, [
            'mode' => 'first_push_sms_then_email',
            'first_engagement_channels' => ['push', 'sms'],
            'subsequent_engagement_channels' => ['email'],
        ]);
        $lead->set(IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value, 1);

        $activity = new FakeSalesAssistNotificationActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertSame(['mail'], $channels);
    }

    public function testWithoutStructuredRulesItFallsBackToExistingBehavior(): void
    {
        $lead = Lead::factory()->create();
        $activity = new FakeSalesAssistNotificationActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertNull($channels);
    }

    public function testMarkManagerNotificationSentTracksFirstTimestampAndCount(): void
    {
        $lead = Lead::factory()->create();
        $activity = new FakeSalesAssistNotificationActivity();

        $activity->exposeMarkManagerNotificationSent($lead);
        $firstTimestamp = $lead->get(IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value);

        $this->assertNotEmpty($firstTimestamp);
        $this->assertSame(1, $lead->get(IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value));

        $activity->exposeMarkManagerNotificationSent($lead);

        $this->assertSame($firstTimestamp, $lead->get(IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value));
        $this->assertSame(2, $lead->get(IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value));
    }
}

class FakeSalesAssistNotificationActivity extends BaseAddLeadCommentFromAgentMessageActivity
{
    protected function getIntegration(): IntegrationsEnum
    {
        return IntegrationsEnum::INTERNAL;
    }

    protected function validateCompanyIntegration(Message $message): ?array
    {
        return null;
    }

    protected function addNoteToExternalSystem(Lead $lead, string $note, Message $message, Apps $app): mixed
    {
        return $note;
    }

    public function exposeResolveManagerNotificationChannels(Lead $lead): ?array
    {
        return $this->resolveManagerNotificationChannels($lead);
    }

    public function exposeMarkManagerNotificationSent(Lead $lead): void
    {
        $this->markManagerNotificationSent($lead);
    }
}
