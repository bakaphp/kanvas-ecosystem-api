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
            $this->company->getId(),
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

    public function testRecentFailuresListsOnlyFailuresNewestFirst(): void
    {
        $lead = Lead::factory()->withAppAndCompany($this->testApp->getId(), $this->company->getId())->create();

        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'too_soon'], $this->at, $lead);
        $this->seedEvent('lead.follow_up.skipped', ['reason' => 'crm_sync_failed'], $this->at->copy()->subMinutes(10), $lead);
        $this->seedEvent('lead.follow_up.failed', ['reason' => 'job_failed', 'error' => 'Agent not found'], $this->at, $lead);

        $failures = new LeadFollowUpActivityService()->recentFailures(
            $this->testApp,
            $this->at->copy()->subHour(),
            $this->company->getId(),
            10
        );

        $this->assertSame(['job_failed', 'crm_sync_failed'], array_column($failures, 'reason'));
        $this->assertSame($lead->getId(), $failures[0]['lead_id']);
        $this->assertSame('Agent not found', $failures[0]['error']);
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
