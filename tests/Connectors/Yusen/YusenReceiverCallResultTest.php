<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Jobs\ProcessYusenInventoryBalanceJob;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\TestCase;

/**
 * `ProcessWebhookJob` stores only what the *webhook* returned, which for this connector is just
 * `dispatched: true`. The report is computed later in the queued job, so it has to be written back
 * onto the same receiver call — otherwise an operator opening the receiver log sees an
 * acknowledgement and no numbers.
 */
class YusenReceiverCallResultTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasCompany = Companies::getById($user->getCurrentCompany()->getId());
    }

    public function testWritesTheReportBackOntoTheReceiverCall(): void
    {
        $call = $this->receiverCall();

        $result = new ProcessYusenInventoryBalanceJob(
            app: $this->kanvasApp,
            company: $this->kanvasCompany,
            rawXml: (string) file_get_contents(__DIR__ . '/fixtures/item-balance.xml'),
            fileName: 'item-balance.xml',
            receiverWebhookCallId: $call->getId(),
        )->handle();

        $stored = ReceiverWebhookCall::findOrFail($call->getId())->results;

        $this->assertSame('item-balance.xml', $stored['file_name']);
        $this->assertSame(4, $stored['total_items']);
        $this->assertSame(1, $stored['multi_record_items']);
        $this->assertArrayHasKey('rows', $stored);
        $this->assertSame($result['total_discrepancies'], $stored['total_discrepancies']);

        // The webhook's own ack must survive alongside the report, not be replaced by it.
        $this->assertTrue($stored['dispatched']);
        $this->assertSame('raw_body', $stored['source']);
    }

    public function testRunsFineWithoutAReceiverCallToRecordAgainst(): void
    {
        // The console backfill path has no receiver call; it must not blow up on the write-back.
        $result = new ProcessYusenInventoryBalanceJob(
            app: $this->kanvasApp,
            company: $this->kanvasCompany,
            rawXml: (string) file_get_contents(__DIR__ . '/fixtures/item-balance.xml'),
            fileName: 'item-balance.xml',
        )->handle();

        $this->assertSame(4, $result['total_items']);
    }

    private function receiverCall(): ReceiverWebhookCall
    {
        $receiver = ReceiverWebhook::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->kanvasCompany->getId(),
            'users_id' => auth()->user()->getId(),
        ]);

        return ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => 'https://graphapi.kanvas.dev/v1/receiver/' . $receiver->uuid,
            'headers' => [],
            'payload' => [],
            'raw_payload' => '<WMWROOT/>',
            'status' => 'success',
            'results' => ['dispatched' => true, 'source' => 'raw_body', 'filesystem_id' => null],
        ]);
    }
}
