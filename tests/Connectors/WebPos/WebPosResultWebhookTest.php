<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Connectors\WebPos\Webhooks\WebPosResultWebhookJob;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\Scribe\ElectronicInvoicing\Concerns\CreatesFiscalDocuments;
use Tests\Scribe\ScribeTestCase;

final class WebPosResultWebhookTest extends ScribeTestCase
{
    use CreatesFiscalDocuments;

    private const string ENCF = 'E310000000004';

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'workflow'];

    private ReceiverWebhook $receiver;

    protected function afterScribeSetUp(): void
    {
        Queue::fake();

        $this->receiver = ReceiverWebhook::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'configuration' => [],
        ]);
    }

    public function testAnAuthorizedResultIsRecordedOnTheInvoiceFoundBySystemRef(): void
    {
        $invoice = $this->invoiceAwaitingDgii();

        $result = $this->deliver(['systemRef' => EcfCompliance::current($invoice)->uuid, 'status' => 2, 'feNumber' => self::ENCF, 'authNumber' => 'track-1']);

        $this->assertSame('accepted', $result['status']);
        $compliance = EcfCompliance::read($invoice->refresh());
        $this->assertSame(EcfStatusEnum::ACCEPTED->value, $compliance['status']);
        $this->assertSame('track-1', $compliance['provider_ref']);
    }

    public function testARepeatedOrLateDeliveryDoesNotChangeARecordedFinalResult(): void
    {
        $invoice = $this->invoiceAwaitingDgii();

        $this->deliver(['systemRef' => EcfCompliance::current($invoice)->uuid, 'status' => 2, 'feNumber' => self::ENCF, 'authNumber' => 'track-1']);
        $after = EcfCompliance::read($invoice->refresh());

        $this->deliver(['systemRef' => EcfCompliance::current($invoice)->uuid, 'status' => 2, 'feNumber' => self::ENCF, 'authNumber' => 'track-2']);
        $this->deliver(['systemRef' => EcfCompliance::current($invoice)->uuid, 'status' => 3, 'feNumber' => self::ENCF]);

        $this->assertSame($after, EcfCompliance::read($invoice->refresh()));
        Queue::assertNotPushed(PollEcfResultJob::class);
    }

    public function testAResultForAnInvoiceOfAnotherCompanyIsIgnored(): void
    {
        $result = $this->deliver(['systemRef' => 'not-an-invoice-uuid', 'status' => 2, 'feNumber' => self::ENCF]);

        $this->assertStringContainsString('No fiscal document with systemRef', $result['message']);
    }

    public function testAResultForAnExpensesEcfIsRecordedOnTheExpense(): void
    {
        $expense = $this->draftTestExpense(500.0, $this->seedTestEmployee()->getId());
        $fiscal = $this->fiscalRowFor($expense, 'E430000000001');

        $result = $this->deliver(['systemRef' => $fiscal->uuid, 'status' => 2, 'feNumber' => 'E430000000001', 'authNumber' => 'track-43']);

        $this->assertSame('Expense', $result['document_type']);
        $this->assertSame($expense->getId(), $result['document_id']);
        $this->assertSame(EcfStatusEnum::ACCEPTED, EcfCompliance::status($expense->refresh()));
        $this->assertSame('track-43', EcfCompliance::read($expense)['provider_ref']);
    }

    public function testALateAnswerAboutASupersededNumberStaysOnThatRowOnly(): void
    {
        $expense = $this->draftTestExpense(500.0, $this->seedTestEmployee()->getId());
        $old = $this->fiscalRowFor($expense, 'E430000000001');
        $current = $this->fiscalRowFor($expense, 'E430000000002');

        $this->deliver(['systemRef' => $old->uuid, 'status' => 2, 'feNumber' => 'E430000000001', 'authNumber' => 'late']);

        $this->assertSame('accepted', $old->refresh()->status->value);
        $this->assertFalse($old->isCurrent());
        $this->assertSame($current->getKey(), EcfCompliance::current($expense)->getKey());
        $this->assertSame(EcfStatusEnum::WAITING, EcfCompliance::status($expense));
    }

    public function testAResultFoundByTheAttemptsSystemRefIsApplied(): void
    {
        $invoice = $this->invoiceAwaitingDgii();
        $attempt = $this->submissionFor(EcfCompliance::current($invoice), null);

        $result = $this->deliver(['systemRef' => $attempt->uuid, 'status' => 2, 'feNumber' => self::ENCF, 'authNumber' => 'track-a1']);

        $this->assertSame('accepted', $result['status']);
    }

    public function testALateAnswerAboutAnEarlierAttemptDoesNotOverwriteTheResendInFlight(): void
    {
        $invoice = $this->invoiceAwaitingDgii();
        $fiscal = EcfCompliance::current($invoice);
        $rejected = $this->submissionFor($fiscal, null);
        $this->submissionFor($fiscal, null, attempt: 2);

        $result = $this->deliver(['systemRef' => $rejected->uuid, 'status' => 3, 'feNumber' => self::ENCF]);

        $this->assertStringContainsString('superseded by attempt 2', $result['message']);
        $this->assertSame(EcfStatusEnum::WAITING, EcfCompliance::status($invoice->refresh()));
    }

    public function testAResultWithoutAKnownStatusIsReportedNotThrown(): void
    {
        $invoice = $this->invoiceAwaitingDgii();

        $result = $this->deliver(['systemRef' => EcfCompliance::current($invoice)->uuid, 'feNumber' => self::ENCF]);

        $this->assertStringContainsString('no known status', $result['message']);
        $this->assertSame(EcfStatusEnum::WAITING->value, EcfCompliance::read($invoice->refresh())['status']);
    }

    public function testASharedSecretOnTheReceiverIsEnforced(): void
    {
        $this->receiver->configuration = ['secret' => 's3cret-value'];

        $this->assertFalse(WebPosResultWebhookJob::authenticateRequest(Request::create('/receiver/x', 'POST'), $this->receiver));
        $this->assertFalse(WebPosResultWebhookJob::authenticateRequest(Request::create('/receiver/x?token=wrong', 'POST'), $this->receiver));
        $this->assertTrue(WebPosResultWebhookJob::authenticateRequest(Request::create('/receiver/x?token=s3cret-value', 'POST'), $this->receiver));
    }

    private function invoiceAwaitingDgii(): Invoice
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Cliente Ejemplo SRL'), 300.0, 54.0);

        $this->fiscalRowFor($invoice, self::ENCF);

        return $invoice;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function deliver(array $payload): array
    {
        $call = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver->getId(),
            'url' => 'https://example.test/receiver/' . $this->receiver->uuid,
            'headers' => [],
            'payload' => $payload,
            'status' => 'pending',
        ]);

        return (array) new WebPosResultWebhookJob($call)->handle();
    }
}
