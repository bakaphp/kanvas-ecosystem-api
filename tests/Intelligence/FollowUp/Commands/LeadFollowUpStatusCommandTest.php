<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Commands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Tests\TestCase;

/**
 * The clock is moved five years ahead so the window only holds what this test seeds.
 */
final class LeadFollowUpStatusCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testShowsTheCompanyWithItsFailuresWhenFilteredByCompany(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        Carbon::setTestNow(now()->addYears(5));

        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.sent',
            ['channels' => ['sms']]
        );
        $this->seedEvent(
            $app,
            $company,
            'lead.follow_up.send_failed',
            ['reason' => 'send_failed', 'channel' => 'sms', 'error' => 'Twilio rejected the number']
        );

        $this->artisan('lead:follow-up-status', [
            '--app_id' => $app->getId(),
            '--company_id' => $company->getId(),
            '--hours' => 1,
        ])
            ->expectsOutputToContain('send_failed (1)')
            ->expectsOutputToContain('Twilio rejected the number')
            ->expectsOutputToContain('Some companies have failing follow-ups')
            ->assertSuccessful();
    }

    private function seedEvent(
        Apps $app,
        Companies $company,
        string $eventType,
        array $payload
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
                occurredAt: now(),
            ),
        )->execute();
    }
}
