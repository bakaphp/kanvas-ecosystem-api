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
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Exceptions\FiscalNumberHeldElsewhereException;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfProviderResolver;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalPayloadService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Asks the provider about one fiscal row by its systemRef while the authority has not decided, including a
 * send whose outcome we never read (`unknown`, or a `sending` left by a crash). The delay grows with each
 * attempt and stops after MAX_ATTEMPTS, leaving `poll_exhausted` and a ledger event so the agent chases it
 * rather than assuming it was accepted.
 */
final class PollEcfResultJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int MAX_ATTEMPTS = 12;
    public const int BASE_DELAY_MINUTES = 2;
    public const int MAX_DELAY_MINUTES = 30;

    public function __construct(
        public readonly Apps $app,
        public readonly FiscalDocument $fiscalDocument,
        public readonly int $attempt = 1,
    ) {
    }

    public static function schedule(FiscalDocument $fiscal, int $attempt): void
    {
        if ($attempt > self::MAX_ATTEMPTS) {
            self::giveUp($fiscal);

            return;
        }

        $minutes = min(self::BASE_DELAY_MINUTES * $attempt, self::MAX_DELAY_MINUTES);

        self::dispatch($fiscal->app, $fiscal, $attempt)->delay(now()->addMinutes($minutes));
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $fiscal = $this->fiscalDocument->refresh();

        if (! $fiscal->status->isOutstanding()) {
            return;
        }

        try {
            $answer = new EcfProviderResolver()->resolveFor($fiscal)
                ->fetchResult(FiscalPayloadService::forFiscal($fiscal));
        } catch (FiscalNumberHeldElsewhereException $e) {
            $fiscal->flagNumberHeldElsewhere($e->getMessage());

            return;
        }

        new ApplyFiscalAnswerAction(
            fiscalDocument: $fiscal,
            answer: $answer,
            pollAttempt: $this->attempt,
        )->execute();
    }

    private static function giveUp(FiscalDocument $fiscal): void
    {
        $fiscal->mergeAuthorityData(['poll_exhausted' => true]);
        $fiscal->save();

        $entity = $fiscal->scribeDocument();

        if ($entity === null) {
            return;
        }

        $entity->emitLedgerEvent(
            eventType: EcfCompliance::eventName($entity, 'ecf_result_overdue'),
            status: EventStatusEnum::INFO,
            payload: [
                'invoice_number' => $entity instanceof Invoice ? $entity->invoice_number : null,
                'fiscal_document_id' => $fiscal->getKey(),
                'encf' => $fiscal->number,
                'attempts' => self::MAX_ATTEMPTS,
            ],
        );
    }
}
