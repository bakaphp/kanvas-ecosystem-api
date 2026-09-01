<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Webhooks;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\PdfIngest\Jobs\ProcessAccountingPdfJob;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Throwable;

/**
 * Mailgun inbound webhook for the Scribe accounting inbox.
 *
 * Production routing:
 *   1. Tenant sets up a Mailgun route forwarding `accounting@<tenant>.example.com` to
 *      POST {API_BASE}/receiver/{receiver_uuid}.
 *   2. ReceiverController (routes/api.php) looks up the ReceiverWebhook row by uuid, calls
 *      `authenticateRequest()`, and dispatches an instance of this class (it's ShouldQueue
 *      via the parent ProcessWebhookJob).
 *   3. `execute()` iterates the email's PDF attachments — already stored as Filesystem rows
 *      by the receiver pipeline at `payload.uploaded_files` — and dispatches one
 *      ProcessAccountingPdfJob per PDF.
 *
 * Each PDF lands as its own PdfIngestLog row + its own draft Expense / Bill (the operator
 * resolves multi-PDF emails by approving each separately).
 *
 * Why a webhook + a SEPARATE per-PDF job:
 *   - The webhook returns 200 fast (Mailgun retries on slow responses)
 *   - Each Gemini classify can take 5-15s — fanning out per attachment means one slow PDF
 *     doesn't hold up the email's other attachments
 *   - Mailgun message_id is captured per-job so dedup (Path 1) works across re-deliveries
 *
 * Tenant ReceiverWebhook config (JSON column `configuration`):
 *   {
 *     "scribe_pdf_inbox": true,             # gate so non-accounting Mailgun routes don't trigger
 *     "default_from_email_when_missing": "ops@example.com"
 *   }
 *
 * Auth: `authenticateRequest()` verifies Mailgun's HMAC signature using the signing key stored
 * on the receiver's app (per `VerifyMailgunWebhookSignatureAction`).
 */
#[WorkflowAction(
    name: 'Mailgun Accounting PDF Inbox',
    description: 'Receiver for the accounting inbox: every PDF attached to an inbound email becomes its own '
        . 'draft expense or bill for an operator to approve. One draft per PDF, so a multi-invoice '
        . 'email is approved piece by piece. Nothing is posted automatically and nobody is contacted. '
        . 'The receiver config must set scribe_pdf_inbox true, which is what stops other Mailgun routes '
        . 'from triggering it.',
    integration: IntegrationsEnum::MAILGUN,
)]
class ScribePdfInboundWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public static function capturesFiles(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(): array
    {
        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $payload = $this->webhookRequest->payload ?? [];
        $messageId = $this->extractMessageId($payload);
        $fromEmail = $this->extractFromEmail($payload);
        $fromName = $this->extractFromName($payload);
        $subject = $this->extractSubject($payload);
        $inboundMetadata = [
            'mailgun_event_id' => $payload['event-id'] ?? null,
            'mailgun_timestamp' => $payload['timestamp'] ?? null,
            'mailgun_subject' => $subject,
            'webhook_request_id' => $this->webhookRequest->getId(),
        ];

        $pdfFilesystemIds = $this->resolvePdfFilesystemIds($payload);

        if ($pdfFilesystemIds === []) {
            Log::info('Scribe.Mailgun.PdfInbound — no PDF attachments captured for this email', [
                'message_id' => $messageId,
                'from_email' => $fromEmail,
                'subject' => $subject,
            ]);

            return [
                'dispatched' => 0,
                'message_id' => $messageId,
                'reason' => 'no_pdf_attachments',
            ];
        }

        $dispatched = 0;
        foreach ($pdfFilesystemIds as $filesystemId) {
            $pdf = Filesystem::query()
                ->where('id', $filesystemId)
                ->where('apps_id', $app->getId())
                ->first();

            if ($pdf === null) {
                Log::warning('Scribe.Mailgun.PdfInbound — Filesystem row missing for captured attachment', [
                    'filesystem_id' => $filesystemId,
                    'app_id' => $app->getId(),
                ]);

                continue;
            }

            ProcessAccountingPdfJob::dispatch(
                $app,
                $company,
                $pdf,
                $messageId,
                $fromEmail,
                $fromName,
                $subject,
                $inboundMetadata,
            );
            $this->recordArrival(
                $app,
                $company,
                $pdf,
                [
                    'message_id' => $messageId,
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'subject' => $subject,
                ]
            );

            $dispatched++;
        }

        return [
            'dispatched' => $dispatched,
            'total_attachments_in_payload' => count($pdfFilesystemIds),
            'message_id' => $messageId,
            'from_email' => $fromEmail,
            'subject' => $subject,
        ];
    }

    /**
     * The first entry on an AP document's timeline: the email that delivered it.
     *
     * Anchored to the PDF, not the bill — the bill does not exist yet, the agent creates it
     * downstream. That is also the stitch: `pdf_ingest_logs.filesystem_id` ties the document the agent
     * produces back to this same Filesystem row, so a desk renders the timeline with
     * `ledgerEvents(where: source_entity_type EQ Filesystem AND source_entity_id EQ <id>)` — both
     * indexed columns.
     *
     * One event per attachment, because one email carrying three invoices is three documents.
     * The Mailgun Message-Id rides in the payload: `correlation_id` is char(36) and holds UUIDs.
     *
     * Best-effort: an audit line must never cost us an inbound invoice.
     *
     * @param array<string, mixed> $payload
     */
    private function recordArrival(
        AppInterface $app,
        CompanyInterface $company,
        Filesystem $pdf,
        array $payload
    ): void {
        try {
            new AppendEventAction(
                new EventData(
                    app: $app,
                    company: $company,
                    sourceDomain: 'Scribe',
                    eventType: 'scribe.document.email_received',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: Filesystem::class,
                    sourceEntityId: (int) $pdf->getKey(),
                    actorType: 'System',
                    payload: $payload,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return new VerifyMailgunWebhookSignatureAction(
            $receiver->app,
            $receiver->company,
            (string) $request->input('token', ''),
            (string) $request->input('timestamp', ''),
            (string) $request->input('signature', ''),
        )->execute();
    }

    /**
     * Walk `payload.uploaded_files` for entries whose mime/extension says "this is a PDF".
     * The receiver pipeline runs BEFORE this job — it has already stored attachments as
     * Filesystem rows and dropped their ids into the payload under that key.
     *
     * Filters to PDFs only — emails commonly carry inline images and other attachment types
     * we don't process here. Non-PDF attachments are silently skipped (no dropped warning).
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function resolvePdfFilesystemIds(array $payload): array
    {
        $files = $payload['uploaded_files'] ?? null;
        if (! is_array($files)) {
            return [];
        }

        $ids = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $isPdf = $this->isPdfAttachment($file);
            if (! $isPdf) {
                continue;
            }

            $filesystemId = $file['filesystem_id'] ?? $file['id'] ?? null;
            if ($filesystemId === null) {
                continue;
            }
            $ids[] = (int) $filesystemId;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function isPdfAttachment(array $file): bool
    {
        $contentType = strtolower((string) ($file['content-type'] ?? $file['mime'] ?? ''));
        if (str_contains($contentType, 'pdf')) {
            return true;
        }

        $name = strtolower((string) ($file['name'] ?? ''));

        return str_ends_with($name, '.pdf');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMessageId(array $payload): ?string
    {
        $candidates = [
            $payload['Message-Id'] ?? null,
            $payload['message-id'] ?? null,
            $payload['message_id'] ?? null,
            $payload['message_headers'][0][1] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFromEmail(array $payload): ?string
    {
        $candidates = [
            $payload['sender'] ?? null,
            $payload['from'] ?? null,
            $payload['From'] ?? null,
            $this->receiver->configuration['default_from_email_when_missing'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFromName(array $payload): ?string
    {
        $candidates = [
            $payload['sender_name'] ?? null,
            $payload['from-name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSubject(array $payload): ?string
    {
        $subject = $payload['subject'] ?? $payload['Subject'] ?? null;

        return is_string($subject) && $subject !== '' ? $subject : null;
    }
}
