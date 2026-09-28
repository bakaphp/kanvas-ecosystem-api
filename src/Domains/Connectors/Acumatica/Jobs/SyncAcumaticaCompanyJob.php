<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PullBillsAction;
use Kanvas\Connectors\Acumatica\Actions\PullChartOfAccountsAction;
use Kanvas\Connectors\Acumatica\Actions\PullCustomersAction;
use Kanvas\Connectors\Acumatica\Actions\PullFiscalPeriodsAction;
use Kanvas\Connectors\Acumatica\Actions\PullInvoicesAction;
use Kanvas\Connectors\Acumatica\Actions\PullJournalEntriesAction;
use Kanvas\Connectors\Acumatica\Actions\PullProductsAction;
use Kanvas\Connectors\Acumatica\Actions\PullPurchaseOrdersAction;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Connectors\Acumatica\Actions\PullStockAction;
use Kanvas\Connectors\Acumatica\Actions\PullSubaccountsAction;
use Kanvas\Connectors\Acumatica\Actions\PullWarehousesAction;
use Kanvas\Connectors\Acumatica\Enums\SyncEntityEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaSyncService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Runs one incremental sync pass for a single Acumatica legal entity (one Kanvas company).
 *
 * Master data (accounts, periods, warehouses) is pulled in full — small + idempotent. The
 * high-volume tables are pulled incrementally off a per-entity high-watermark: only rows with
 * LastModifiedDateTime past the stored cursor. On a first run (no cursor) we fall back to a bounded
 * lookback window rather than all history — that's what keeps a scheduled run from OOMing the way an
 * unbounded backfill does. Each entity's cursor advances only after that entity succeeds, and one
 * entity failing never blocks the others.
 */
final class SyncAcumaticaCompanyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    private const int INITIAL_LOOKBACK_DAYS = 30;
    private const int OVERLAP_LOCK_TTL = 3900;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly int $acumaticaCompanyId,
        public readonly ?Regions $region = null,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('acumatica-sync-' . (string) $this->company->getId())
                ->expireAfter(self::OVERLAP_LOCK_TTL)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $sync = new AcumaticaSyncService();

        $this->runFull(fn (): int => new PullChartOfAccountsAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
        )->execute());

        $this->runFull(fn (): int => new PullSubaccountsAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
        )->execute());

        $this->runFull(fn (): int => new PullFiscalPeriodsAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::CUSTOMERS, fn (?Carbon $since): int => new PullCustomersAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            isVendor: false,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::VENDORS, fn (?Carbon $since): int => new PullCustomersAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            isVendor: true,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::PURCHASE_ORDERS, fn (?Carbon $since): int => new PullPurchaseOrdersAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::JOURNAL_ENTRIES, fn (?Carbon $since): int => new PullJournalEntriesAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::INVOICES, fn (?Carbon $since): int => new PullInvoicesAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::BILLS, fn (?Carbon $since): int => new PullBillsAction(
            $this->app,
            $this->company,
            $this->user,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runInventory($sync);
    }

    /**
     * Inventory pulls need a region; a finance-only company (no region) still syncs GL/AR/AP.
     */
    private function runInventory(AcumaticaSyncService $sync): void
    {
        if ($this->region === null) {
            return;
        }

        $region = $this->region;

        $this->runFull(fn (): int => new PullWarehousesAction(
            $this->app,
            $this->company,
            $this->user,
            $region,
            $this->acumaticaCompanyId,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::PRODUCTS, fn (?Carbon $since): int => new PullProductsAction(
            $this->app,
            $this->company,
            $this->user,
            $region,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::STOCK, fn (?Carbon $since): int => new PullStockAction(
            $this->app,
            $this->company,
            $this->user,
            $region,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());

        $this->runIncremental($sync, SyncEntityEnum::ORDERS, fn (?Carbon $since): int => new PullSalesOrdersAction(
            $this->app,
            $this->company,
            $this->user,
            $region,
            $this->acumaticaCompanyId,
            modifiedSince: $since,
        )->execute());
    }

    private function runFull(callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function runIncremental(AcumaticaSyncService $sync, SyncEntityEnum $entity, callable $run): void
    {
        $runAt = Carbon::now();
        $since = $sync->cursorFor($this->company, $entity)
            ?? $runAt->copy()->subDays(self::INITIAL_LOOKBACK_DAYS);

        try {
            $run($since);
            $sync->advanceCursor($this->company, $entity, $runAt);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
