<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\ElectronicInvoicing\Actions\RecordFiscalSubmissionAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;
use Throwable;

/**
 * An attempt still `sending` long after it started is one whose worker died between the insert and the
 * answer. We cannot know whether the provider got it, so it becomes `unknown` and is resolved by asking the
 * provider by systemRef — never by sending again, which could spend a second number.
 */
class ResolveStuckFiscalSubmissionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:fiscal-resolve-stuck-submissions
                            {--minutes=15 : How long an attempt may stay sending before it is treated as unknown}';

    protected $description = 'Turn fiscal submissions stuck in sending into unknown and queue a status query for them.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinutes(max(1, (int) $this->option('minutes')));
        $resolved = 0;

        $appIds = FiscalDocumentSubmission::query()
            ->where('status', FiscalSubmissionStatusEnum::SENDING->value)
            ->where('sent_at', '<', $cutoff)
            ->distinct()
            ->pluck('apps_id');

        foreach ($appIds as $appId) {
            $app = Apps::find((int) $appId);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);

            $stuck = FiscalDocumentSubmission::query()
                ->where('apps_id', $app->getId())
                ->where('status', FiscalSubmissionStatusEnum::SENDING->value)
                ->where('sent_at', '<', $cutoff)
                ->get();

            foreach ($stuck as $attempt) {
                try {
                    $resolved += (int) $this->resolve($attempt);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("Resolved {$resolved} stuck fiscal submission(s) as unknown.");

        return self::SUCCESS;
    }

    private function resolve(FiscalDocumentSubmission $attempt): bool
    {
        return DB::connection('accounting')->transaction(function () use ($attempt): bool {
            /** @var FiscalDocument $fiscal */
            $fiscal = FiscalDocument::query()->whereKey($attempt->fiscal_document_id)->lockForUpdate()->firstOrFail();
            $attempt->refresh();

            if ($attempt->status !== FiscalSubmissionStatusEnum::SENDING || $fiscal->status !== FiscalDocumentStatusEnum::SENDING) {
                return false;
            }

            RecordFiscalSubmissionAction::markUnknown($fiscal, $attempt, 'No answer was recorded for this send; its outcome is being queried.');

            return true;
        });
    }
}
