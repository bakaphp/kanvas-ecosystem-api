<?php

declare(strict_types=1);

namespace Tests\ActionEngine\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\UpdateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\DataTransferObject\UpdateEngagement as UpdateEngagementData;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: attaching `sync` writes the global `apps_id = 0` actions row every process shares.
 */
#[Group('serial')]
final class SyncEngagementTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private Apps $currentApp;
    private Companies $company;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        // See EngagementCompletedBroadcastTest: the lead factory's ledger emission would otherwise
        // run inline and open an extra connection per test.
        Queue::fake();

        $this->lead = Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->company->getId())
            ->create();

        // The factory leaves the lead branchless, which would make the branch assertion below
        // pass through getByAction's branch-less fallback instead of the branch lane.
        $this->lead->companies_branches_id = $this->company->branch()->firstOrFail()->getId();
        $this->lead->saveOrFail();

        $this->artisan('kanvas-action-engine:attach-action', [
            'app_id' => $this->currentApp->getId(),
            'slug' => ActionEnum::SYNC->value,
            '--company' => $this->company->getId(),
            '--create-action' => true,
        ])->assertSuccessful();
    }

    public function testActionEnumDefinesSync(): void
    {
        $this->assertSame('sync', ActionEnum::SYNC->value);
    }

    public function testCreatesASyncEngagementWithTheSubmittedStage(): void
    {
        $engagement = $this->createSyncEngagement();

        $this->assertSame(ActionEnum::SYNC->value, $engagement->slug);
        $this->assertSame(
            ActionStatusEnum::SUBMITTED->value,
            $engagement->stage->slug
        );
        $this->assertSame(
            $this->lead->branch->getId(),
            (int) $engagement->companyAction->companies_branches_id
        );
    }

    public function testMessagePayloadCarriesFromDataAndCrmAtTopLevel(): void
    {
        $payload = $this->createSyncEngagement()->message->getMessage();

        $this->assertSame(ActionStatusEnum::SUBMITTED->value, $payload['status']);
        $this->assertSame(['contact.first_name' => 'Sarah', 'contact.zip' => '94601'], $payload['data']);
        $this->assertSame('update-lead', $payload['from']);
    }

    /**
     * Spatie emits every declared property, so putting `from` on EngagementMessage would stamp
     * "from": null onto all 63 action types. Only `sync` sends it.
     */
    public function testAnEngagementWithoutASourceCarriesNoFromKey(): void
    {
        $payload = $this->createSyncEngagement(withSource: false)->message->getMessage();

        $this->assertArrayNotHasKey('from', $payload);
    }

    public function testCreatesTheSyncMessageTypeAutomatically(): void
    {
        $this->createSyncEngagement();

        $this->assertTrue(
            MessageType::where('apps_id', $this->currentApp->getId())
                ->where('verb', ActionEnum::SYNC->value)
                ->exists()
        );
    }

    /**
     * The regression for UpdateEngagementAction's `is_array(...) ?: []` fallback, which wiped the
     * whole payload — status included — whenever the body was stored double-encoded.
     */
    public function testUpdateEngagementPreservesFromAndStatusOnADoubleEncodedBody(): void
    {
        $engagement = $this->createSyncEngagement();
        $original = $engagement->message->getMessage();

        DB::connection('social')
            ->table('messages')
            ->where('id', $engagement->message_id)
            ->update(['message' => json_encode(json_encode($original))]);

        new UpdateEngagementAction(
            $engagement->fresh(),
            UpdateEngagementData::from(['data' => ['contact.zip' => '10001']])
        )->execute();

        $payload = $engagement->fresh()->message->getMessage();

        $this->assertSame(['contact.zip' => '10001'], $payload['data']);
        $this->assertSame(ActionStatusEnum::SUBMITTED->value, $payload['status']);
        $this->assertSame($original['from'], $payload['from']);
    }

    private function createSyncEngagement(bool $withSource = true)
    {
        $data = $withSource
            ? $this->engagementData('update-lead')
            : $this->engagementData(null);

        return new CreateEngagementAction($data)->execute();
    }

    private function engagementData(?string $from): EngagementData
    {
        return EngagementData::from(
            $this->currentApp,
            $this->company,
            auth()->user(),
            $this->lead,
            array_filter([
                'action' => ActionEnum::SYNC->value,
                'request_id' => Str::uuid()->toString(),
                'source' => 'sales-assist-extension',
                'status' => ActionStatusEnum::SUBMITTED->value,
                'data' => ['contact.first_name' => 'Sarah', 'contact.zip' => '94601'],
                'from' => $from,
            ], fn ($value): bool => $value !== null)
        );
    }
}
