<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\TaxReversalStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\CommitTaxTransactionJob;
use Kanvas\Souk\Taxes\Jobs\ReverseTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxOperation;
use Kanvas\Souk\Payments\Models\PaymentRefund;

class TaxOperationsReconcileCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'souk:tax-operations-reconcile {app_id?}';

    protected $description = 'Retries failed/stuck tax operations and surfaces needs_reconcile rows';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->processApp((int) $appsId);

            return;
        }

        $appsIds = Settings::where([
            'name' => ConfigurationEnum::TAX_ENGINE_ENABLED->value,
            'value' => '1',
        ])->select('apps_id')->get()->pluck('apps_id');

        foreach ($appsIds as $appId) {
            $this->processApp((int) $appId);
        }
    }

    protected function processApp(int $appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $this->retryFailedCommits($app);
        $this->retryFailedReversals($app);
        $this->reportNeedsReconcile($app);
    }

    private function retryFailedCommits(Apps $app): void
    {
        $failedSnapshots = OrderTaxSnapshot::query()
            ->where('apps_id', $app->getId())
            ->where('status', TaxStatusEnum::COMMIT_FAILED->value)
            ->get();

        foreach ($failedSnapshots as $snapshot) {
            $snapshot->transition(
                TaxStatusEnum::COMMIT_FAILED->value,
                TaxStatusEnum::COMMITTING->value
            );

            CommitTaxTransactionJob::dispatch($snapshot, $app);

            $this->info("Retrying commit for snapshot {$snapshot->uuid}");
        }
    }

    private function retryFailedReversals(Apps $app): void
    {
        $failedOps = TaxOperation::query()
            ->where('kind', 'reverse')
            ->where('status', TaxReversalStatusEnum::REVERSAL_FAILED->value)
            ->whereHas(
                'snapshot',
                fn ($q) => $q->where('apps_id', $app->getId())
            )
            ->with('snapshot')
            ->get();

        foreach ($failedOps as $operation) {
            $snapshot = $operation->snapshot;

            if ($snapshot === null) {
                continue;
            }

            $refundUuid = $operation->provider_response['refund_uuid'] ?? null;

            $refund = $refundUuid !== null
                ? PaymentRefund::query()->where('uuid', $refundUuid)->first()
                : null;

            if ($refund === null) {
                $this->warn("Cannot retry reversal for operation {$operation->idempotency_key}: refund not found");

                \Sentry\captureMessage(
                    "Cannot retry tax reversal for snapshot {$snapshot->uuid}: refund UUID missing from operation metadata"
                );

                continue;
            }

            $operation->status = TaxReversalStatusEnum::PENDING->value;
            $operation->saveOrFail();

            $isFullReversal = (float) $refund->amount >= (float) $snapshot->remaining_reversible_amount;

            ReverseTaxTransactionJob::dispatch(
                $snapshot,
                $refund,
                (float) $refund->amount,
                $isFullReversal,
                $app,
            );

            $this->info("Retrying reversal for snapshot {$snapshot->uuid}");
        }
    }

    private function reportNeedsReconcile(Apps $app): void
    {
        $reconcileSnapshots = OrderTaxSnapshot::query()
            ->where('apps_id', $app->getId())
            ->where('status', TaxStatusEnum::NEEDS_RECONCILE->value)
            ->get();

        foreach ($reconcileSnapshots as $snapshot) {
            $message = "Tax snapshot {$snapshot->uuid} (order_id={$snapshot->order_id}) needs manual reconciliation";

            $this->warn($message);

            \Sentry\captureMessage($message);
        }

        $reconcileOps = TaxOperation::query()
            ->where('status', TaxReversalStatusEnum::NEEDS_RECONCILE->value)
            ->whereHas(
                'snapshot',
                fn ($q) => $q->where('apps_id', $app->getId())
            )
            ->with('snapshot')
            ->get();

        foreach ($reconcileOps as $operation) {
            $message = "Tax operation {$operation->idempotency_key} (kind={$operation->kind}) needs manual reconciliation";

            $this->warn($message);

            \Sentry\captureMessage($message);
        }
    }

}
