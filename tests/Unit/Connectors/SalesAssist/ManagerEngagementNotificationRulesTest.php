<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\SalesAssist;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\BaseAddLeadCommentFromAgentMessageActivity;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use NotificationChannels\Expo\ExpoChannel;
use PHPUnit\Framework\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowOptions;

final class ManagerEngagementNotificationRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testStructuredRulesUsePushAndSmsForFirstCustomerEngagement(): void
    {
        $lead = $this->mockLead([
            IntelligenceConfigurationEnum::AI_ENGAGEMENT_MANAGER_NOTIFICATION_RULES->value => [
                'mode' => 'first_push_sms_then_email',
                'first_engagement_channels' => ['push', 'sms'],
                'subsequent_engagement_channels' => ['email'],
            ],
        ]);

        $activity = $this->makeActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertSame([
            OneSignalNotificationChannel::class,
            ExpoChannel::class,
            TwilioSmsChannel::class,
        ], $channels);
    }

    public function testStructuredRulesUseEmailForSubsequentCustomerEngagement(): void
    {
        $leadValues = [
            IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value => 1,
        ];

        $lead = $this->mockLead(
            [
                IntelligenceConfigurationEnum::AI_ENGAGEMENT_MANAGER_NOTIFICATION_RULES->value => [
                    'mode' => 'first_push_sms_then_email',
                    'first_engagement_channels' => ['push', 'sms'],
                    'subsequent_engagement_channels' => ['email'],
                ],
            ],
            $leadValues
        );

        $activity = $this->makeActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertSame(['mail'], $channels);
    }

    public function testWithoutStructuredRulesItFallsBackToExistingBehavior(): void
    {
        $lead = $this->mockLead();
        $activity = $this->makeActivity();

        $channels = $activity->exposeResolveManagerNotificationChannels($lead);

        $this->assertNull($channels);
    }

    public function testMarkManagerNotificationSentTracksFirstTimestampAndCount(): void
    {
        $leadValues = [];
        $lead = $this->mockLead([], $leadValues);
        $activity = $this->makeActivity();

        $activity->exposeMarkManagerNotificationSent($lead);
        $firstTimestamp = $leadValues[IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value] ?? null;

        $this->assertNotEmpty($firstTimestamp);
        $this->assertSame(1, $leadValues[IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value] ?? null);

        $activity->exposeMarkManagerNotificationSent($lead);

        $this->assertSame(
            $firstTimestamp,
            $leadValues[IntelligenceConfigurationEnum::AI_MANAGER_FIRST_CUSTOMER_ENGAGEMENT_NOTIFIED_AT->value] ?? null
        );
        $this->assertGreaterThanOrEqual(
            1,
            $leadValues[IntelligenceConfigurationEnum::AI_MANAGER_CUSTOMER_ENGAGEMENT_NOTIFICATION_COUNT->value] ?? 0
        );
    }

    private function makeActivity(): FakeSalesAssistNotificationActivity
    {
        $storedWorkflow = Mockery::mock(StoredWorkflow::class);
        $storedWorkflow->shouldReceive('workflowOptions')
            ->andReturn(new WorkflowOptions());

        return new FakeSalesAssistNotificationActivity(1, date(DATE_ATOM), $storedWorkflow);
    }

    /**
     * @param array<string, mixed> $companyValues
     * @param array<string, mixed> $leadValues
     */
    private function mockLead(array $companyValues = [], array &$leadValues = []): Lead
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')
            ->withArgs(static fn (string $key) => array_key_exists($key, $companyValues))
            ->andReturnUsing(static fn (string $key) => $companyValues[$key]);
        $company->shouldReceive('get')
            ->withArgs(static fn (string $key) => ! array_key_exists($key, $companyValues))
            ->andReturn(null);

        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->company = $company;

        $lead->shouldReceive('get')
            ->andReturnUsing(static fn (string $key) => $leadValues[$key] ?? null);
        $lead->shouldReceive('set')
            ->andReturnUsing(static function (string $key, mixed $value) use (&$leadValues): AppsCustomFields {
                $leadValues[$key] = $value;

                return Mockery::mock(AppsCustomFields::class);
            });

        return $lead;
    }
}

final class FakeSalesAssistNotificationActivity extends BaseAddLeadCommentFromAgentMessageActivity
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
