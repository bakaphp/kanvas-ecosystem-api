<?php

declare(strict_types=1);

namespace Tests\Connectors\Alanube;

use Baka\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Enums\WebhookEventEnum;
use Kanvas\Connectors\Alanube\Services\AlanubeWebhookSecretService;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeCancellationFinishedWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeCompanyStatusWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeDocumentReceivedWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeEmissionFinishedWebhookJob;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeWebhookJob;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Bills\Actions\CreateBillFromReceivedEcfAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\GenerateEcfPdfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Connectors\Traits\HasAlanubeConfiguration;
use Tests\Scribe\ElectronicInvoicing\Concerns\CreatesFiscalDocuments;
use Tests\TestCase;

final class AlanubeWebhookJobsTest extends TestCase
{
    use CreatesFiscalDocuments;
    use DatabaseTransactions;
    use HasAlanubeConfiguration;

    private const string DOCUMENT_ID = '01G021Z3QSRZ58GSTBH7TPGD2J';
    private const string ENCF = 'E310000000004';

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm'];

    private Companies $alanube;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAlanubeWorkflowActions();
        $this->alanube = $this->alanubeCompany(AccountModeEnum::OWN);
        $this->secret = new AlanubeWebhookSecretService($this->alanube)->currentOrCreate();
    }

    public function testTheSecretHeaderIsRequiredAndComparedExactly(): void
    {
        $receiver = $this->receiver(AlanubeEmissionFinishedWebhookJob::class);

        $this->assertFalse(AlanubeWebhookJob::authenticateRequest($this->request([]), $receiver));
        $this->assertFalse(AlanubeWebhookJob::authenticateRequest($this->request($this->secretHeader('wrong')), $receiver));
        $this->assertFalse(AlanubeWebhookJob::authenticateRequest($this->request($this->secretHeader(substr($this->secret, 0, -1))), $receiver));
        $this->assertTrue(AlanubeWebhookJob::authenticateRequest($this->request($this->secretHeader($this->secret)), $receiver));
    }

    public function testAnotherCompanysSecretDoesNotOpenThisReceiver(): void
    {
        $other = $this->alanubeCompany(AccountModeEnum::OWN);
        $otherSecret = new AlanubeWebhookSecretService($other)->currentOrCreate();

        $this->assertFalse(AlanubeWebhookJob::authenticateRequest(
            $this->request($this->secretHeader($otherSecret)),
            $this->receiver(AlanubeEmissionFinishedWebhookJob::class),
        ));
    }

    public function testTheReceiverRefusesADeliveryWithoutTheSecretAndAnswersTheConnectivityTest(): void
    {
        $receiver = $this->receiver(AlanubeEmissionFinishedWebhookJob::class);
        $url = '/v1/receiver/' . $receiver->uuid;

        $this->postJson($url, ['id' => self::DOCUMENT_ID])->assertStatus(401);
        $this->postJson($url, ['message' => 'Test message'], $this->secretHeader('wrong'))->assertStatus(401);
        $this->postJson($url, ['message' => 'Test message'], $this->secretHeader($this->secret))
            ->assertOk()
            ->assertJson(['message' => 'ok']);

        $this->assertSame(0, ReceiverWebhookCall::query()->where('receiver_webhooks_id', $receiver->getId())->count());
    }

    public function testEmissionFinishedRecordsTheResultOnceThenIsANoOp(): void
    {
        $invoice = Invoice::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->alanube->getId(),
            'currency' => 'DOP',
            'fx_rate_to_base' => 1,
        ]);
        $this->submissionFor($this->fiscalRowFor($invoice, self::ENCF, provider: 'alanube'), self::DOCUMENT_ID);
        Http::fake([self::ALANUBE_SANDBOX . 'fiscal-invoices/' . self::DOCUMENT_ID => Http::response([
            'id' => self::DOCUMENT_ID,
            'status' => 'FINISHED',
            'legalStatus' => 'ACCEPTED',
            'documentNumber' => self::ENCF,
            'securityCode' => 'MYUVCT',
        ])]);
        Queue::fake([GenerateEcfPdfJob::class]);

        $first = $this->deliver(AlanubeEmissionFinishedWebhookJob::class, ['id' => self::DOCUMENT_ID, 'status' => 'FINISHED']);
        $second = $this->deliver(AlanubeEmissionFinishedWebhookJob::class, ['id' => self::DOCUMENT_ID, 'status' => 'FINISHED']);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('accepted', $second['status']);
        $invoice->refresh();
        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($invoice));
        $this->assertSame('MYUVCT', EcfCompliance::read($invoice)['security_code']);
        $this->assertSame(1, $this->events('scribe.invoice.ecf_accepted'));
        Queue::assertPushed(GenerateEcfPdfJob::class, fn (GenerateEcfPdfJob $job): bool => $job->invoice->is($invoice));
        Queue::assertPushed(GenerateEcfPdfJob::class, 1);
    }

    public function testEmissionFinishedForAnUnknownDocumentDoesNothing(): void
    {
        Http::fake();

        $result = $this->deliver(AlanubeEmissionFinishedWebhookJob::class, ['id' => 'NOT-OURS', 'documentNumber' => 'E319999999999']);

        $this->assertSame('No fiscal document for this e-CF in this company.', $result['message']);
        Http::assertNothingSent();
    }

    public function testEmissionFinishedRecordsTheResultOnABillsPurchaseEcf(): void
    {
        $encf = 'E410000000007';
        $bill = $this->bill();
        $this->submissionFor($this->fiscalRowFor($bill, $encf, provider: 'alanube'), self::DOCUMENT_ID);
        Http::fake([self::ALANUBE_SANDBOX . 'purchases/' . self::DOCUMENT_ID => Http::response([
            'id' => self::DOCUMENT_ID,
            'status' => 'FINISHED',
            'legalStatus' => 'ACCEPTED',
            'documentNumber' => $encf,
        ])]);

        $result = $this->deliver(AlanubeEmissionFinishedWebhookJob::class, ['id' => self::DOCUMENT_ID]);

        $this->assertSame('Bill', $result['document_type']);
        $this->assertSame($bill->getId(), $result['document_id']);
        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($bill->refresh()));
    }

    public function testEmissionFinishedNeverMatchesAVendorsEcfByItsNumber(): void
    {
        $this->fiscalRowFor(
            $this->bill(),
            self::ENCF,
            status: FiscalDocumentStatusEnum::ACCEPTED,
            provider: 'alanube',
            direction: FiscalDirectionEnum::RECEIVED,
            issuerTaxId: '131000009',
        );
        Http::fake();

        $result = $this->deliver(AlanubeEmissionFinishedWebhookJob::class, ['documentNumber' => self::ENCF]);

        $this->assertSame('No fiscal document for this e-CF in this company.', $result['message']);
        Http::assertNothingSent();
    }

    public function testCancellationFinishedIsRecordedOnce(): void
    {
        Http::fake([self::ALANUBE_SANDBOX . 'cancellations/01CANCEL' => Http::response([
            'id' => '01CANCEL',
            'status' => 'FINISHED',
            'stampDate' => '2026-06-15',
        ])]);

        $this->deliver(AlanubeCancellationFinishedWebhookJob::class, ['id' => '01CANCEL']);
        $second = $this->deliver(AlanubeCancellationFinishedWebhookJob::class, ['id' => '01CANCEL']);

        $this->assertSame('Cancellation already recorded.', $second['message']);
        $this->assertSame(1, $this->events(AlanubeCancellationFinishedWebhookJob::LEDGER_EVENT));
        Http::assertSentCount(1);
    }

    public function testCompanyStatusIsStoredAndOnlyAChangeIsRecorded(): void
    {
        $this->deliver(AlanubeCompanyStatusWebhookJob::class, ['status' => 'ENABLED']);
        $this->deliver(AlanubeCompanyStatusWebhookJob::class, ['status' => 'ENABLED']);
        $this->deliver(AlanubeCompanyStatusWebhookJob::class, ['status' => 'DISABLED']);

        $this->assertSame('DISABLED', $this->alanube->get(ConfigurationEnum::GOVERNMENT_STATUS->value));
        $this->assertSame(2, $this->events(AlanubeCompanyStatusWebhookJob::LEDGER_EVENT));
    }

    public function testAReceivedVendorEcfBecomesOneBillHoweverOftenItIsDelivered(): void
    {
        $xmlUrl = 'https://alanube-files.example/received/01RECEIVED.xml';
        Http::fake([self::ALANUBE_SANDBOX . 'received-documents/01RECEIVED' => Http::response([
            'id' => '01RECEIVED',
            'issuerIdentification' => '131000009',
            'documentNumber' => 'E310000009001',
            'status' => 'RECEIVED',
            'xml' => $xmlUrl,
        ])]);
        SafeUrlFetcher::fake(function (string $url) use ($xmlUrl): string {
            $this->assertSame($xmlUrl, $url);

            return (string) file_get_contents(__DIR__ . '/fixtures/received-e31.xml');
        });

        $first = $this->deliver(AlanubeDocumentReceivedWebhookJob::class, ['id' => '01RECEIVED']);
        $second = $this->deliver(AlanubeDocumentReceivedWebhookJob::class, ['id' => '01RECEIVED']);

        $this->assertSame($first['bill_id'], $second['bill_id']);
        $bill = Bill::query()->findOrFail($first['bill_id']);
        $this->assertSame('E310000009001', $bill->bill_number);
        $this->assertSame('alanube', $bill->metadata['received_ecf']['provider']);
        $this->assertEqualsWithDelta(1180.0, (float) $bill->total_native, 0.001);
        $this->assertSame(1, Bill::query()
            ->where('companies_id', $this->alanube->getId())
            ->where('source', CreateBillFromReceivedEcfAction::SOURCE)
            ->count());
    }

    public function testADocumentAlanubeRefusedIsNotBooked(): void
    {
        Http::fake([self::ALANUBE_SANDBOX . 'received-documents/01REFUSED' => Http::response([
            'id' => '01REFUSED',
            'status' => 'NOT_RECEIVED',
            'errorMsg' => 'Firma invalida',
        ])]);

        $result = $this->deliver(AlanubeDocumentReceivedWebhookJob::class, ['id' => '01REFUSED']);

        $this->assertSame('Document was not received.', $result['message']);
    }

    public function testACommercialApprovalFromACustomerIsRecordedOnce(): void
    {
        Http::fake([
            self::ALANUBE_SANDBOX . 'received-documents/01APPROVAL' => Http::response(['message' => 'No se ha encontrado el recurso', 'code' => 404], 404),
            self::ALANUBE_SANDBOX . 'received-commercial-approvals/01APPROVAL' => Http::response([
                'id' => '01APPROVAL',
                'documentNumber' => self::ENCF,
                'commercialResponse' => 'ACCEPTED',
            ]),
        ]);

        $this->deliver(AlanubeDocumentReceivedWebhookJob::class, ['id' => '01APPROVAL']);
        $this->deliver(AlanubeDocumentReceivedWebhookJob::class, ['id' => '01APPROVAL']);

        $this->assertSame(1, $this->events(AlanubeDocumentReceivedWebhookJob::APPROVAL_LEDGER_EVENT));
    }

    private function bill(): Bill
    {
        return Bill::query()->create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->alanube->getId(),
            'currency' => 'DOP',
            'fx_rate_to_base' => 1,
        ]);
    }

    /**
     * @param class-string<AlanubeWebhookJob> $jobClass
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function deliver(string $jobClass, array $payload): array
    {
        $receiver = $this->receiver($jobClass);
        $call = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => $this->secretHeader('[redacted]'),
            'payload' => $payload,
            'status' => 'pending',
        ]);

        $result = new $jobClass($call)->handle();
        $call->refresh();

        $this->assertSame('success', $call->status, json_encode($call->exception));

        return $result;
    }

    private function receiver(string $jobClass): ReceiverWebhook
    {
        $action = WorkflowAction::query()->where('model_name', $jobClass)->firstOrFail();

        return ReceiverWebhook::query()
            ->where('companies_id', $this->alanube->getId())
            ->where('action_id', $action->getId())
            ->first()
            ?? ReceiverWebhook::factory()
                ->app(app(Apps::class)->getId())
                ->company($this->alanube->getId())
                ->user((int) auth()->id())
                ->create([
                    'action_id' => $action->getId(),
                    'configuration' => ['alanube_event' => WebhookEventEnum::EMISSION_FINISHED->value],
                    'is_active' => true,
                    'run_async' => true,
                ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create(
            '/v1/receiver/x',
            'POST',
            [],
            [],
            [],
            $server
        );
    }

    private function events(string $eventType): int
    {
        return Event::query()
            ->where('companies_id', $this->alanube->getId())
            ->where('event_type', $eventType)
            ->count();
    }
}
