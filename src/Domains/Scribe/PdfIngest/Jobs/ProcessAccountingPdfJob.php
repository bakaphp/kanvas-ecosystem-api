<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;

/**
 * Queued wrapper around ProcessAccountingPdfAction.
 *
 * Dispatched by the inbound webhook handler after the PDF is stored in Filesystem. Constructor
 * takes raw Eloquent models + primitives (not a Spatie Data DTO) per the
 * "never queue Spatie Data DTOs with Eloquent models" rule — the DTO is rebuilt inside handle().
 *
 * Calls overwriteAppService($this->app) FIRST per the standard rule for any queued job that touches
 * an Apps model — Bouncer scope from the previous job would otherwise leak.
 *
 * Queue: `scribe-pdf-ingest` — separate from scribe-aging because PDF ingest has very different
 * volume + latency characteristics (Gemini call ~5s, may scale to many docs/min).
 */
final class ProcessAccountingPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Filesystem $pdf,
        public readonly ?string $messageId = null,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $subject = null,
        public readonly ?array $inboundMetadata = null,
    ) {
        $this->onQueue('scribe-pdf-ingest');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->app,
                company: $this->company,
                pdf: $this->pdf,
                messageId: $this->messageId,
                fromEmail: $this->fromEmail,
                fromName: $this->fromName,
                subject: $this->subject,
                inboundMetadata: $this->inboundMetadata,
            ),
        )->execute();
    }
}
