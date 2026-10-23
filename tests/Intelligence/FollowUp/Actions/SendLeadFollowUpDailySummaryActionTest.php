<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Actions\SendLeadFollowUpDailySummaryAction;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpEmailTemplateEnum;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the recipients list is a company setting, which lives in Redis and is shared by every
 * parallel test process.
 */
#[Group('serial')]
final class SendLeadFollowUpDailySummaryActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    private const string RECIPIENTS = ConfigurationEnum::LEAD_FOLLOW_UP_SUMMARY_RECIPIENTS->value;

    private Apps $testApp;

    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->testApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        $this->company->del(self::RECIPIENTS);

        parent::tearDown();
    }

    public function testEmailsEachValidRecipientWithTheFailedLeadsNamed(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create(['title' => 'Maria Follow Up']);
        $this->company->set(self::RECIPIENTS, 'ops@example.com, not-an-email, ops@example.com, sales@example.com');

        $sentTo = new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary([
                'sent' => ['total' => 2, 'by_channel' => ['sms' => 2], 'by_stage' => []],
                'failed' => [
                    'total' => 1,
                    'by_reason' => ['send_failed' => 1],
                    'recent' => [['lead_id' => $lead->getId(), 'reason' => 'send_failed', 'error' => 'Twilio 21211', 'channel' => 'sms']],
                ],
            ]),
        )->execute();

        $this->assertSame(['ops@example.com', 'sales@example.com'], $sentTo);
        Notification::assertSentOnDemandTimes(Blank::class, 2);
        Notification::assertSentOnDemand(
            Blank::class,
            static function (Blank $notification) use ($lead): bool {
                $failure = $notification->getData()['failed']['recent'][0];

                return $notification->getTemplateName() === FollowUpEmailTemplateEnum::DAILY_SUMMARY->value
                    && $failure['lead_id'] === $lead->getId()
                    && $failure['lead_title'] === 'Maria Follow Up';
            }
        );
    }

    public function testSendsNothingWithoutRecipients(): void
    {
        $this->company->del(self::RECIPIENTS);

        $sentTo = new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary(['sent' => ['total' => 3, 'by_channel' => [], 'by_stage' => []]]),
        )->execute();

        $this->assertSame([], $sentTo);
        Notification::assertNothingSent();
    }

    public function testSendsNothingForADayWithoutActivity(): void
    {
        $this->company->set(self::RECIPIENTS, 'ops@example.com');

        $sentTo = new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary(['skipped' => ['total' => 40, 'by_reason' => ['too_soon' => 40]]]),
        )->execute();

        $this->assertSame([], $sentTo);
        Notification::assertNothingSent();
    }

    private function summary(array $payload): Event
    {
        return new AppendEventAction(
            new EventData(
                app: $this->testApp,
                company: $this->company,
                sourceDomain: 'follow_up',
                eventType: 'follow_up.daily.summary',
                status: EventStatusEnum::INFO,
                sourceEntityType: null,
                sourceEntityId: null,
                payload: [...$payload, 'for_date' => '2026-10-21'],
            ),
        )->execute();
    }
}
