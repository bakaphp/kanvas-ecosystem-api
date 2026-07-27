<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Runs the heavy per-record upsert outside the console process — dispatched by
 * `SalesforceBackfillCommand` with the full page of raw records collected by
 * `PullAllOrganizationsAction`/`PullAllPeopleAction`. Reuses the already-tested
 * `PullOrganizationAction`/`PullPeopleAction` (same custom-field matching, same
 * `disableWorkflows()` anti-loop guard) instead of reimplementing the upsert here.
 */
class SalesforceBackfillImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $salesforceObjectType,
        public readonly array $records,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if (! in_array($this->salesforceObjectType, ['Account', 'Contact'], true)) {
            Log::error('Salesforce backfill import received an unsupported object type', [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'salesforce_object_type' => $this->salesforceObjectType,
            ]);

            return;
        }

        $processed = 0;
        $failed = 0;

        // The whole per-record body — including the Id cast — lives inside the try/catch. A
        // record with a malformed `Id` (or anything else that blows up) must only fail that one
        // record, not the rest of the batch.
        foreach ($this->records as $record) {
            try {
                $salesforceId = (string) ($record['Id'] ?? '');

                if ($salesforceId === '') {
                    throw new ValidationException('Salesforce record is missing an Id');
                }

                $this->importRecord($salesforceId, $record);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        Log::info('Salesforce backfill import finished', [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'salesforce_object_type' => $this->salesforceObjectType,
            'processed' => $processed,
            'failed' => $failed,
        ]);
    }

    private function importRecord(string $salesforceId, array $record): void
    {
        match ($this->salesforceObjectType) {
            'Account' => new PullOrganizationAction($this->app, $this->company, $record, $salesforceId)->execute(),
            'Contact' => new PullPeopleAction($this->app, $this->company, $record, $salesforceId)->execute(),
            default => throw new ValidationException("Unsupported Salesforce object type: {$this->salesforceObjectType}"),
        };
    }
}
