<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\VerifyMailgunWebhookSignatureAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\AccountingInboxService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\PdfIngest\Jobs\ProcessAccountingPdfJob;
use Kanvas\Scribe\PdfIngest\Services\InboxActingUserService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Throwable;

/**
 * The accounting inbox receiver (provisioned by EnsureAccountingInboxAction). The attachments are already
 * Filesystem rows by the time this runs (`payload.uploaded_files`, see capturesFiles()); each PDF becomes
 * its own ProcessAccountingPdfJob, processed as the company's AP agent user so the bill is submitted for
 * approval like one the agent filed itself.
 *
 * One job per PDF, not inline: Mailgun retries a slow 200, a Gemini classify takes 5-15s, and one slow
 * attachment must not hold up the email's others. The Mailgun message id rides on each job for dedup
 * across re-deliveries.
 *
 * Receiver configuration: `scribe_pdf_inbox: true` is the gate (any other Mailgun route pointed here is
 * ignored); `default_from_email_when_missing` may be added by hand for senders Mailgun does not name.
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

        if (! AccountingInboxService::isAccountingInbox($this->receiver)) {
            Log::warning('Scribe.Mailgun.PdfInbound — receiver is not flagged scribe_pdf_inbox, ignoring email', [
                'receiver_id' => $this->receiver->getId(),
            ]);

            return [
                'dispatched' => 0,
                'reason' => 'not_an_accounting_inbox',
            ];
        }

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

        $actingUser = InboxActingUserService::resolve($app, $company, $this->receiver->user);

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
                $actingUser,
            );
            $this->recordArrival($pdf, [
                'message_id' => $messageId,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'subject' => $subject,
            ]);

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
    private function recordArrival(Filesystem $pdf, array $payload): void
    {
        try {
            new AppendEventAction(
                new EventData(
                    app: $this->receiver->app,
                    company: $this->receiver->company,
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
        return VerifyMailgunWebhookSignatureAction::forRequest($request, $receiver)->execute();
    }

    /**
     * Inline images and other attachment types are skipped without a warning: an email signature is not
     * a document.
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
            if (! is_array($file) || ! $this->isPdfAttachment($file)) {
                continue;
            }

            $filesystemId = $file['filesystem_id'] ?? $file['id'] ?? null;
            if ($filesystemId !== null) {
                $ids[] = (int) $filesystemId;
            }
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
        return $this->firstString(
            $payload['Message-Id'] ?? null,
            $payload['message-id'] ?? null,
            $payload['message_id'] ?? null,
            $payload['message_headers'][0][1] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFromEmail(array $payload): ?string
    {
        return $this->firstString(
            $payload['sender'] ?? null,
            $payload['from'] ?? null,
            $payload['From'] ?? null,
            $this->receiver->configuration['default_from_email_when_missing'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFromName(array $payload): ?string
    {
        return $this->firstString($payload['sender_name'] ?? null, $payload['from-name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSubject(array $payload): ?string
    {
        return $this->firstString($payload['subject'] ?? null, $payload['Subject'] ?? null);
    }

    private function firstString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
