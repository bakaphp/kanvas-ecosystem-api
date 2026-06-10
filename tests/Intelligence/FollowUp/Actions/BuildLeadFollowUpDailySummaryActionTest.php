<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\Actions\BuildLeadFollowUpDailySummaryAction;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

/**
 * Verifies the daily aggregator reads the right events for the date range,
 * aggregates them correctly, and emits a single follow_up.daily.summary
 * event with the expected payload shape.
 */
class BuildLeadFollowUpDailySummaryActionTest extends TestCase
{
    use DatabaseTransactions;

    // Ledger events live on the intelligence connection; without it the
    // events written by the first test leak into the second test, breaking
    // the date-range exclusion assertion.
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testAggregatesYesterdaysEventsIntoOneSummary(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Yesterday in tenant-local time.
        $tz = $company->timezone ?? 'UTC';
        $yesterday = Carbon::yesterday($tz);
        $occurredAt = $yesterday->copy()->setTimezone($tz)->setTime(12, 0, 0)->utc();

        // Seed a variety of events on yesterday.
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.sent',
            ['channels' => ['whatsapp'], 'stage_id' => 7],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.sent',
            ['channels' => ['whatsapp'], 'stage_id' => 7],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.sent',
            ['channels' => ['sms'], 'stage_id' => 9],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.skipped',
            ['reason' => 'too_soon'],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.exhausted',
            ['reason' => 'agent: lead disengaged'],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.exhausted',
            ['reason' => 'agent: different reason'],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.exhausted',
            ['reason' => 'max_retries'],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.resumed',
            ['reason' => 'inbound_reply'],
            $occurredAt
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.pipeline.completed',
            ['reason' => 'agent_advanced_to_terminal'],
            $occurredAt
        );

        $summary = new BuildLeadFollowUpDailySummaryAction(
            app: $app,
            company: $company,
            forDate: $yesterday,
        )->execute();

        $this->assertSame('follow_up.daily.summary', $summary->event_type);
        $payload = $summary->payload;

        $this->assertSame(3, $payload['sent']['total']);
        $this->assertSame(2, $payload['sent']['by_channel']['whatsapp']);
        $this->assertSame(1, $payload['sent']['by_channel']['sms']);
        $this->assertSame(2, $payload['sent']['by_stage']['7']);
        $this->assertSame(1, $payload['sent']['by_stage']['9']);

        $this->assertSame(1, $payload['skipped']['total']);
        $this->assertSame(1, $payload['skipped']['by_reason']['too_soon']);

        // Reason normalization: BOTH "agent: lead disengaged" AND "agent: different reason"
        // should collapse into a single "agent" bucket of 2.
        $this->assertSame(3, $payload['exhausted']['total']);
        $this->assertSame(2, $payload['exhausted']['by_reason']['agent']);
        $this->assertSame(1, $payload['exhausted']['by_reason']['max_retries']);

        $this->assertSame(1, $payload['resumed']['total']);
        $this->assertSame(1, $payload['completed']['total']);
    }

    public function testIgnoresEventsOutsideTheDateRange(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tz = $company->timezone ?? 'UTC';
        $yesterday = Carbon::yesterday($tz);
        $twoDaysAgo = Carbon::today($tz)->subDays(2)->setTime(12, 0, 0)->utc();

        // This event is 2 days old — should NOT be in yesterday's summary.
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.sent',
            ['channels' => ['sms'], 'stage_id' => 1],
            $twoDaysAgo
        );

        $summary = new BuildLeadFollowUpDailySummaryAction(
            app: $app,
            company: $company,
            forDate: $yesterday,
        )->execute();

        $this->assertSame(0, $summary->payload['sent']['total']);
    }

    private function seedEvent(
        Apps $app,
        $company,
        string $eventType,
        array $payload,
        Carbon $occurredAt,
    ): void {
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'follow_up',
                eventType: $eventType,
                status: EventStatusEnum::INFO,
                sourceEntityType: Lead::class,
                sourceEntityId: 1,
                payload: $payload,
                occurredAt: $occurredAt,
            ),
        )->execute();
    }
}
