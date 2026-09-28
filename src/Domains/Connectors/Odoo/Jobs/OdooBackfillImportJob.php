<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Odoo\Actions\PullLeadAction;
use Kanvas\Connectors\Odoo\Actions\PullOrganizationAction;
use Kanvas\Connectors\Odoo\Actions\PullPeopleAction;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Runs the per-record upsert outside the console process, reusing the `Pull*Action` classes so
 * the backfill inherits their custom-field matching and `runWorkflow: false` anti-loop guard.
 *
 * The entity type is "Organization"/"People"/"Lead", not the Odoo model name — Odoo maps both
 * Organization and People onto `res.partner` (split by `is_company`), so the model name alone
 * can't say which `Pull*Action` a record belongs to.
 */
class OdooBackfillImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const array SUPPORTED_ENTITY_TYPES = ['Organization', 'People', 'Lead'];

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $entityType,
        public readonly array $records,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if (! in_array($this->entityType, self::SUPPORTED_ENTITY_TYPES, true)) {
            Log::error('Odoo backfill import received an unsupported entity type', [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'entity_type' => $this->entityType,
            ]);

            return;
        }

        $processed = 0;
        $failed = 0;

        // The whole per-record body — including the id cast — lives inside the try/catch. A
        // record with a malformed id (or anything else that blows up) must only fail that one
        // record, not the rest of the batch.
        foreach ($this->records as $record) {
            try {
                $odooId = (string) ($record['id'] ?? '');

                if ($odooId === '') {
                    throw new ValidationException('Odoo record is missing an id');
                }

                $this->importRecord($odooId, $record);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        Log::info('Odoo backfill import finished', [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'entity_type' => $this->entityType,
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }

    private function importRecord(string $odooId, array $record): void
    {
        match ($this->entityType) {
            'Organization' => new PullOrganizationAction(
                $this->app,
                $this->company,
                $record,
                $odooId,
            )->execute(),
            'People' => new PullPeopleAction(
                $this->app,
                $this->company,
                $record,
                $odooId,
            )->execute(),
            'Lead' => new PullLeadAction(
                $this->app,
                $this->company,
                $record,
                $odooId,
            )->execute(),
        };
    }
}
