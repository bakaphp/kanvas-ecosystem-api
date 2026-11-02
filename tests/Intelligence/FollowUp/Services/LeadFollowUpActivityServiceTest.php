<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpActivityService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Tests\TestCase;

/**
 * Events are seeded five years ahead so rows other tests write today never land in the window.
 */
final class LeadFollowUpActivityServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $testApp;

    private Companies $company;

    private Carbon $at;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->at = now()->addYears(5);
    }

    public function testCountsSentAndFailuresPerCompanyKeepingFailuresOutOfSkipped(): void
    {
        $this->seedEvent('lead.follow_up.queued', []);
        $this->seedEvent('lead.follow_up.sent', ['channels' => ['sms']]);
        $this->seedEvent('lead.follow_up.sent', ['channels' => ['email']]);
        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'too_soon']);
        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'agent: wait for Sunday']);
        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'agent_call_failed: Gemini timeout']);
        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'no_reachable_channel']);
        $this->seedEvent('lead.follow_up.send_failed', ['reason' => 'send_failed', 'channel' => 'sms', 'error' => 'Twilio 21211']);
        $this->seedEvent('lead.follow_up.exhausted', ['reason' => 'max_retries']);
        // Outside the window.
        $this->seedEvent('lead.follow_up.sent', ['channels' => ['sms']], $this->at->copy()->subDays(3));

        $counts = new LeadFollowUpActivityService()->countsByCompany(
            $this->testApp,
            $this->at->copy()->subHour(),
            $this->at,
            $this->company->getId()
        );

        $this->assertSame([$this->company->getId()], array_keys($counts));
        $company = $counts[$this->company->getId()];
        $this->assertSame(1, $company['queued']);
        $this->assertSame(2, $company['sent']);
        $this->assertSame(2, $company['skipped']);
        $this->assertSame(3, $company['failed']);
        $this->assertSame(1, $company['exhausted']);
        $this->assertEquals(
            ['agent_call_failed' => 1, 'no_reachable_channel' => 1, 'send_failed' => 1],
            $company['failed_by_reason']
        );
        $this->assertNotNull($company['last_sent_at']);
    }

    public function testSummaryListsTheLatestFailuresNewestFirstWithTheLeadTitle(): void
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create(['title' => 'Maria Follow Up']);

        $this->seedEvent(
            'lead.follow_up.skipped',
            ['reason' => 'too_soon'],
            $this->at,
            $lead
        );
        $this->seedEvent(
            'lead.follow_up.skipped',
            ['reason' => 'crm_sync_failed'],
            $this->at->copy()->subMinutes(10),
            $lead
        );
        $this->seedEvent(
            'lead.follow_up.failed',
            ['reason' => 'job_failed', 'error' => 'Agent not found'],
            $this->at,
            $lead
        );
        $this->seedEvent(
            'lead.follow_up.sent',
            ['channels' => ['sms', 'email']],
            $this->at,
            $lead
        );
        // After the window ends.
        $this->seedEvent(
            'lead.follow_up.send_failed',
            ['reason' => 'send_failed', 'error' => 'Twilio 21211'],
            $this->at->copy()->addHours(2),
            $lead
        );

        $summary = new LeadFollowUpActivityService()->summarize(
            $this->testApp,
            $this->company,
            $this->at->copy()->subHour(),
            $this->at->copy()->addHour(),
        );

        $this->assertSame(2, $summary['failed']['total']);
        $this->assertSame(['job_failed', 'crm_sync_failed'], array_column($summary['failed']['recent'], 'reason'));
        $this->assertSame('Agent not found', $summary['failed']['recent'][0]['error']);
        $this->assertSame('Maria Follow Up', $summary['failed']['recent'][0]['lead_title']);
        $this->assertSame(['too_soon' => 1], $summary['skipped']['by_reason']);
        $this->assertSame(['sms' => 1, 'email' => 1], $summary['sent']['by_channel']);
    }

    private function seedEvent(
        string $eventType,
        array $payload,
        ?Carbon $occurredAt = null,
        ?Lead $lead = null
    ): void {
        new AppendEventAction(
            new EventData(
                app: $this->testApp,
                company: $this->company,
                sourceDomain: 'follow_up',
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: Lead::class,
                sourceEntityId: $lead?->getId() ?? 1,
                payload: $payload,
                occurredAt: $occurredAt ?? $this->at,
            ),
        )->execute();
    }
}
