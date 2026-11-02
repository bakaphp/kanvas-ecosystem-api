<?php

declare(strict_types=1);

namespace Tests\Guild\Deals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class DealClosedAtTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    private Apps $testApp;

    private Companies $company;

    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        /** @var Users $authedUser */
        $authedUser = auth()->user();
        $this->user = $authedUser;
        $this->company = $this->user->getCurrentCompany();
    }

    public function testClosingTheStatusStampsClosedAtAndEmitsLedgerEvent(): void
    {
        $sold = $this->makeStatus('Sold');
        $deal = $this->makeDeal();

        $this->assertNull($deal->closed_at);
        $openStatusId = (int) $deal->status_id;

        Carbon::setTestNow('2026-10-23 15:00:00');
        $deal->status_id = $sold->getId();
        $deal->save();
        Carbon::setTestNow();

        $deal->refresh();
        $this->assertSame('2026-10-23 15:00:00', $deal->closed_at?->format('Y-m-d H:i:s'));

        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'deal.status.changed')
            ->where('source_entity_type', Deal::class)
            ->where('source_entity_id', $deal->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($openStatusId, $event->payload['from_status_id'] ?? null);
        $this->assertSame($sold->getId(), $event->payload['to_status_id'] ?? null);
    }

    public function testReopeningClearsClosedAt(): void
    {
        $sold = $this->makeStatus('Sold');
        $deal = $this->makeDeal();
        $openStatusId = (int) $deal->status_id;

        $deal->status_id = $sold->getId();
        $deal->save();
        $this->assertNotNull($deal->refresh()->closed_at);

        $deal->status_id = $openStatusId;
        $deal->save();
        $this->assertNull($deal->refresh()->closed_at);
    }

    public function testADealCreatedAlreadyClosedIsStampedOnCreate(): void
    {
        $sold = $this->makeStatus('Sold');

        $deal = Deal::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'owner_id' => $this->user->getId(),
            'status_id' => $sold->getId(),
            'title' => 'Imported sold unit',
        ]);

        $this->assertNotNull($deal->refresh()->closed_at);
    }

    private function makeDeal(): Deal
    {
        return Deal::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'owner_id' => $this->user->getId(),
            'title' => 'Deal ' . uniqid(),
        ]);
    }

    private function makeStatus(string $name): LeadStatus
    {
        return LeadStatus::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'name' => $name,
            'is_default' => 0,
        ]);
    }
}
