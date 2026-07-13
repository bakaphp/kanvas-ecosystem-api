<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Acumatica;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Services\AcumaticaSyncService;
use Throwable;

/**
 * The big first load: runs a wide-window full pull for every sync-enabled Kanvas company (or one
 * explicit target), so the agents see the whole book instead of the 30-day slice the scheduled
 * incremental sync keeps fresh. Delegates each company to `kanvas:acumatica-pull` — same ordering,
 * same per-entity counts — and just orchestrates the multi-company loop + the backfill window.
 *
 * The window ONLY bounds the historical documents (purchase-orders, orders, journal-entries,
 * invoices, bills) — default 24 months, which covers every realistically-open AR/AP item without
 * dragging a decade of paid ones; `--all-history` lifts it, `--since` sets it explicitly. Inventory
 * and the other current-state data (products, stock, customers, vendors, warehouses, accounts,
 * subaccounts, periods) is ALWAYS pulled in full — a window there would silently drop a product or
 * an on-hand quantity that simply hasn't changed recently.
 */
class BackfillAcumaticaCommand extends Command
{
    use KanvasJobsTrait;

    private const int DEFAULT_WINDOW_MONTHS = 24;

    protected $signature = 'kanvas:acumatica-backfill
        {--company_id= : backfill one Kanvas company (default: all SYNC_ENABLED companies)}
        {--app_id= : Kanvas app id (required with --company_id when the company has no stored SYNC config)}
        {--user_id= : Kanvas user to attribute imports to (required with --company_id when unconfigured)}
        {--acumatica_company_id= : Acumatica CompanyID (required with --company_id when unconfigured)}
        {--region_id= : Kanvas region id (optional)}
        {--since= : backfill transactional data modified on/after this ISO date (default: 24 months ago)}
        {--all-history : ignore --since and pull the full history (large tenants: slow + memory-heavy)}
        {--only=all : entity subset passed through to the pull}
        {--limit= : cap rows per entity (recommended for a first / test run)}';

    protected $description = 'Backfill Acumatica data (wide window) for one or all sync-enabled Kanvas companies.';

    public function handle(): int
    {
        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->warn('No backfill targets. Enable a company (SYNC_ENABLED + SYNC_* settings) or pass --company_id with --app_id/--user_id/--acumatica_company_id.');

            return self::SUCCESS;
        }

        $since = (bool) $this->option('all-history') ? null : $this->resolveSince();
        $window = $since !== null ? $since->toDateString() : 'ALL HISTORY';

        $this->info(sprintf(
            'Backfilling %d compan%s | transactional window: %s',
            count($targets),
            count($targets) === 1 ? 'y' : 'ies',
            $window
        ));

        $ok = 0;
        $failed = 0;

        foreach ($targets as $target) {
            $this->overwriteAppService($target['app']);

            $this->newLine();
            $this->info("── Company {$target['company_id']} (Acumatica entity {$target['acumatica_company_id']}) ──");

            $arguments = [
                'app_id' => $target['app_id'],
                'company_id' => $target['company_id'],
                'user_id' => $target['user_id'],
                'acumatica_company_id' => $target['acumatica_company_id'],
                '--only' => (string) $this->option('only'),
            ];

            if ($target['region_id'] !== null) {
                $arguments['--region_id'] = $target['region_id'];
            }

            if ($since !== null) {
                $arguments['--since'] = $since->toDateString();
            }

            if (is_numeric($this->option('limit'))) {
                $arguments['--limit'] = (int) $this->option('limit');
            }

            try {
                if ($this->call('kanvas:acumatica-pull', $arguments) === self::SUCCESS) {
                    $ok++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Company {$target['company_id']} backfill failed: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Backfill complete — {$ok} ok, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, array{app: Apps, app_id: int, company_id: int, user_id: int, acumatica_company_id: int, region_id: int|null}>
     */
    private function resolveTargets(): array
    {
        $sync = new AcumaticaSyncService();

        if ($this->option('company_id') !== null) {
            $target = $this->resolveExplicitTarget($sync);

            return $target !== null ? [$target] : [];
        }

        $targets = [];

        foreach ($sync->enabledCompanies() as $company) {
            $resolved = $sync->resolveTarget($company);

            if ($resolved === null) {
                $this->warn("Skipping company {$company->getId()} — SYNC config incomplete or app has no SQL connection.");

                continue;
            }

            $targets[] = [
                'app' => $resolved->app,
                'app_id' => $resolved->app->getId(),
                'company_id' => $resolved->company->getId(),
                'user_id' => $resolved->user->getId(),
                'acumatica_company_id' => $resolved->acumaticaCompanyId,
                'region_id' => $resolved->region?->getId(),
            ];
        }

        return $targets;
    }

    /**
     * @return array{app: Apps, app_id: int, company_id: int, user_id: int, acumatica_company_id: int, region_id: int|null}|null
     */
    private function resolveExplicitTarget(AcumaticaSyncService $sync): ?array
    {
        $appId = $this->option('app_id');

        if (! is_numeric($appId)) {
            $this->error('--company_id requires --app_id.');

            return null;
        }

        /** @var Apps $app */
        $app = Apps::getById((int) $appId);
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->option('company_id'), $app);

        // A configured company can resolve fully from its stored SYNC settings.
        $resolved = $sync->resolveTarget($company);

        if ($resolved !== null) {
            return [
                'app' => $resolved->app,
                'app_id' => $resolved->app->getId(),
                'company_id' => $resolved->company->getId(),
                'user_id' => $resolved->user->getId(),
                'acumatica_company_id' => $resolved->acumaticaCompanyId,
                'region_id' => $resolved->region?->getId(),
            ];
        }

        // Otherwise the operator supplies the mapping explicitly.
        if (! is_numeric($this->option('user_id')) || ! is_numeric($this->option('acumatica_company_id'))) {
            $this->error('Company is not sync-configured — pass --user_id and --acumatica_company_id (and optionally --region_id).');

            return null;
        }

        $regionId = $this->option('region_id');

        return [
            'app' => $app,
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
            'user_id' => (int) $this->option('user_id'),
            'acumatica_company_id' => (int) $this->option('acumatica_company_id'),
            'region_id' => is_numeric($regionId) ? (int) $regionId : null,
        ];
    }

    private function resolveSince(): Carbon
    {
        $option = $this->option('since');

        if (is_string($option) && $option !== '') {
            return Carbon::parse($option);
        }

        return Carbon::now()->subMonths(self::DEFAULT_WINDOW_MONTHS);
    }
}
