<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Webhooks\ScribePdfInboundWebhookJob;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\PdfIngest\Jobs\ProcessAccountingPdfJob;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * Verifies the Mailgun → Scribe PDF ingest webhook end-to-end at the job-execute layer:
 *
 *   - Payload parsing pulls Mailgun fields (message-id, sender, subject) correctly
 *   - PDF attachments (already uploaded by the receiver pipeline) get one ProcessAccountingPdfJob
 *     dispatched per file
 *   - Non-PDF attachments are silently filtered out
 *   - Empty / no-attachment payloads are graceful no-ops (the `dispatched` count = 0)
 *
 * Skips the HTTP signature check — that's covered by VerifyMailgunWebhookSignatureActionTest. This
 * test exercises the routing/fan-out logic specific to this subclass.
 */
class ScribePdfInboundWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    // 'intelligence' carries the ledger arrival event this job now emits.
    protected array $connectionsToTransact = ['mysql', 'crm', 'accounting', 'intelligence'];

    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ScribePdfInboundWebhookJob::class],
            ['name' => 'ScribePdfInboundWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function test_dispatches_one_process_pdf_job_per_pdf_attachment(): void
    {
        $pdfA = $this->seedFilesystemRow('aws-invoice.pdf');
        $pdfB = $this->seedFilesystemRow('vendor-bill.pdf');
        $imageNotPdf = $this->seedFilesystemRow('signature.png', extension: 'png');

        $payload = [
            'Message-Id' => '<msg-' . uniqid() . '@mailgun.example.test>',
            'sender' => 'billing@aws.example.test',
            'subject' => 'Your AWS Invoice',
            'uploaded_files' => [
                ['filesystem_id' => $pdfA->getId(), 'name' => $pdfA->name, 'content-type' => 'application/pdf'],
                ['filesystem_id' => $pdfB->getId(), 'name' => $pdfB->name, 'content-type' => 'application/pdf'],
                ['filesystem_id' => $imageNotPdf->getId(), 'name' => $imageNotPdf->name, 'content-type' => 'image/png'],
            ],
        ];

        $result = $this->runWebhookJob($payload);

        $this->assertSame(2, $result['dispatched'], 'Only the 2 PDF attachments should produce job dispatches.');
        $this->assertSame(2, $result['total_attachments_in_payload']);
        $this->assertSame($payload['sender'], $result['from_email']);
        $this->assertSame($payload['subject'], $result['subject']);

        Queue::assertPushed(
            ProcessAccountingPdfJob::class,
            2,
            'Two PDF attachments → two ProcessAccountingPdfJob dispatches.',
        );
    }

    public function test_no_pdf_attachments_returns_zero_dispatched(): void
    {
        $payload = [
            'Message-Id' => '<no-attachments-' . uniqid() . '@example.test>',
            'sender' => 'someone@example.test',
            'subject' => 'no attachments',
            'uploaded_files' => [],
        ];

        $result = $this->runWebhookJob($payload);

        $this->assertSame(0, $result['dispatched']);
        $this->assertSame('no_pdf_attachments', $result['reason']);
        Queue::assertNotPushed(ProcessAccountingPdfJob::class);
    }

    public function test_filters_non_pdf_attachments_silently(): void
    {
        $image = $this->seedFilesystemRow('not-an-invoice.png', extension: 'png');
        $pdf = $this->seedFilesystemRow('real-invoice.pdf');

        $payload = [
            'Message-Id' => '<mixed-' . uniqid() . '@example.test>',
            'sender' => 'mixed@example.test',
            'uploaded_files' => [
                ['filesystem_id' => $image->getId(), 'name' => $image->name, 'content-type' => 'image/png'],
                ['filesystem_id' => $pdf->getId(), 'name' => $pdf->name, 'content-type' => 'application/pdf'],
            ],
        ];

        $result = $this->runWebhookJob($payload);

        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushed(ProcessAccountingPdfJob::class, 1);
    }

    public function test_passes_message_id_and_sender_through_to_dispatched_job(): void
    {
        $pdf = $this->seedFilesystemRow('billing.pdf');
        $messageId = '<unique-msg-' . uniqid() . '@mailgun.example.test>';
        $sender = 'invoices@vendor.example.test';

        $payload = [
            'Message-Id' => $messageId,
            'sender' => $sender,
            'subject' => 'Invoice attached',
            'uploaded_files' => [
                ['filesystem_id' => $pdf->getId(), 'name' => $pdf->name, 'content-type' => 'application/pdf'],
            ],
        ];

        $this->runWebhookJob($payload);

        Queue::assertPushed(
            ProcessAccountingPdfJob::class,
            fn (ProcessAccountingPdfJob $job) => $job->messageId === $messageId
                && $job->fromEmail === $sender,
        );
    }

    /**
     * The first row of an AP document's timeline. Anchored to the PDF rather than the bill, because
     * the bill does not exist yet — pdf_ingest_logs.filesystem_id is what ties the two together.
     */
    public function test_an_arriving_invoice_email_is_recorded_in_the_ledger(): void
    {
        $pdf = $this->seedFilesystemRow('invoice.pdf');
        $messageId = '<arrival-' . uniqid() . '@mailgun.example.test>';

        $this->runWebhookJob([
            'Message-Id' => $messageId,
            'sender' => 'accounting@northwind.test',
            'from' => 'Northwind Logistics <accounting@northwind.test>',
            'subject' => 'Invoice NW-88214',
            'uploaded_files' => [
                ['filesystem_id' => $pdf->getId(), 'name' => $pdf->name, 'content-type' => 'application/pdf'],
            ],
        ]);

        $event = Event::query()
            ->where('source_entity_type', Filesystem::class)
            ->where('source_entity_id', $pdf->getId())
            ->first();

        $this->assertNotNull($event, 'A UI must be able to show when the invoice arrived.');
        $this->assertSame('scribe.document.email_received', $event->event_type);
        $this->assertSame('Scribe', $event->source_domain);
        $this->assertSame('System', $event->actor_type);
        $this->assertSame('accounting@northwind.test', $event->payload['from_email']);
        $this->assertSame('Invoice NW-88214', $event->payload['subject']);
        $this->assertSame($messageId, $event->payload['message_id']);
    }

    public function test_an_email_with_no_pdf_records_nothing_to_stitch_a_document_to(): void
    {
        $messageId = '<no-pdf-' . uniqid() . '@mailgun.example.test>';

        $this->runWebhookJob([
            'Message-Id' => $messageId,
            'sender' => 'newsletter@vendor.test',
            'subject' => 'Monthly update',
            'uploaded_files' => [],
        ]);

        $this->assertSame(
            0,
            Event::query()
                ->where('event_type', 'scribe.document.email_received')
                ->where('occurred_at', '>=', now()->subMinute())
                ->count(),
            'An email that produces no document has no document timeline to open.'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runWebhookJob(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );
        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $job = new ScribePdfInboundWebhookJob($webhookRequest);

        return $job->execute();
    }

    private function seedFilesystemRow(string $name, string $extension = 'pdf'): Filesystem
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $row = new Filesystem();
        $row->apps_id = $app->getId();
        $row->companies_id = $company->getId();
        $row->users_id = $user->getId();
        $row->name = $name;
        $row->path = 'webhook-test/' . Carbon::now()->format('YmdHisu') . '/' . $name . '.' . Str::random(4);
        $row->url = 'https://example.test/' . $row->path;
        $row->size = '12345';
        $row->file_type = $extension;
        $row->save();

        return $row;
    }
}
