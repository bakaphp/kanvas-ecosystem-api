<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalSubmissionAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Throwable;

/**
 * Sends one fiscal row through the provider it was issued with. Only a draft (or a rejection DGII left
 * reusable) is sent; anything already in flight is the poller's.
 */
final class SubmitEcfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly Apps $app,
        public readonly FiscalDocument $fiscalDocument,
    ) {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $fiscal = $this->fiscalDocument->refresh();

        if ($fiscal->status !== FiscalDocumentStatusEnum::DRAFT && ! $fiscal->isReusable()) {
            return;
        }

        new RecordFiscalSubmissionAction($fiscal)->execute();
    }

    /**
     * After the last retry nothing will send it on its own; the flag is what the agent and the digest see.
     */
    public function failed(Throwable $exception): void
    {
        $fiscal = $this->fiscalDocument->refresh();

        $fiscal->mergeAuthorityData([
            'submission_failed' => true,
            'submission_error' => $exception->getMessage(),
        ]);
        $fiscal->save();
    }
}
