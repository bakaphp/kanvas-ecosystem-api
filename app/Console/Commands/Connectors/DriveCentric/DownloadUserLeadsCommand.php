<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\DriveCentric;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Actions\PullLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Connectors\DriveCentric\Services\LeadUserService;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadClosingStatusAction;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;
use Throwable;

class DownloadUserLeadsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:drivecentric-download-user-leads
                            {app_id : The application ID}
                            {user_id : The Kanvas user ID whose DriveCentric deals should be pulled}
                            {--company_ids= : Comma-separated company IDs (defaults to every company where the user is linked to DriveCentric)}
                            {--crm_id= : DriveCentric CrmId to match, for a user not linked in Kanvas yet}
                            {--start= : Start date in YYYY-MM-DD format (defaults to --months back)}
                            {--end= : End date in YYYY-MM-DD format (defaults to today)}
                            {--months=24 : How far back to sweep when --start is omitted}
                            {--dry-run : List the matching deals without writing anything}';

    protected $description = 'Pull every DriveCentric deal assigned to a specific salesperson, creating the leads we are missing locally';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Users $user */
        $user = Users::getById((int) $this->argument('user_id'));

        $companies = $this->getCompanies($user);

        if ($companies->isEmpty()) {
            $this->error('No companies found where this user is linked to DriveCentric. Pass --company_ids and --crm_id to force it.');

            return self::FAILURE;
        }

        $windows = $this->buildWindows();

        if ($windows === []) {
            $this->error('--start must be on or before --end.');

            return self::FAILURE;
        }

        $this->info("User: {$user->displayname} (ID: {$user->getId()})");
        $this->info('Date range: ' . $windows[0][0] . ' to ' . end($windows)[1] . ' (' . count($windows) . ' monthly windows)');
        $this->newLine();

        foreach ($companies as $company) {
            $crmId = $this->option('crm_id') ?: $user->get(ConfigurationEnum::getUserKey($company));

            if (empty($crmId)) {
                $this->warn("Skipping {$company->name} (ID: {$company->getId()}) — user has no DriveCentric CrmId for it.");

                continue;
            }

            $this->info("=== {$company->name} (ID: {$company->getId()}) — DriveCentric user {$crmId} ===");

            try {
                $this->processCompany(
                    $app,
                    $company,
                    $user,
                    (string) $crmId,
                    $windows
                );
            } catch (Throwable $e) {
                $this->error("Failed to process company {$company->getId()}: " . $e->getMessage());
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function getCompanies(Users $user): Collection
    {
        $companyIdsOption = $this->option('company_ids');

        if ($companyIdsOption) {
            return Companies::whereIn('id', array_map('trim', explode(',', $companyIdsOption)))->get();
        }

        // The DriveCentric CrmId is stored per company as drive_centric_user_{companyId},
        // so the user's own config rows tell us which stores they sell for.
        $companyIds = UserConfig::where('users_id', $user->getId())
            ->where('name', 'LIKE', ConfigurationEnum::USER->value . '_%')
            ->pluck('name')
            ->map(fn (string $name) => (int) str_replace(ConfigurationEnum::USER->value . '_', '', $name))
            ->filter()
            ->unique();

        return Companies::whereIn('id', $companyIds)->get();
    }

    /**
     * byrange is the only listing endpoint DriveCentric exposes and it is always date-bounded, so
     * "download everything" is a sweep of consecutive month-long windows. Chunking also keeps each
     * request small enough to survive the HTTP timeout and lets one bad window fail on its own.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function buildWindows(): array
    {
        $end = $this->option('end')
            ? Carbon::parse($this->option('end'))
            : Carbon::now();

        $start = $this->option('start')
            ? Carbon::parse($this->option('start'))
            : $end->copy()->subMonths((int) $this->option('months'));

        if ($start->gt($end)) {
            return [];
        }

        $windows = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $windowEnd = $cursor->copy()->addMonth()->subDay()->min($end);
            $windows[] = [$cursor->format('Y-m-d'), $windowEnd->format('Y-m-d')];
            $cursor = $windowEnd->copy()->addDay();
        }

        return $windows;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $windows
     */
    private function processCompany(
        Apps $app,
        Companies $company,
        Users $user,
        string $crmId,
        array $windows
    ): void {
        if (! $company->get(ConfigurationEnum::STORE_ID->value)) {
            $this->error('Company does not have DriveCentric store ID configured');

            return;
        }

        $leadService = new LeadService($app, $company);
        $pullLeadAction = new PullLeadAction($app, $company, $user);

        $totals = ['scanned' => 0, 'matched' => 0, 'synced' => 0, 'errors' => 0];

        foreach ($windows as [$windowStart, $windowEnd]) {
            try {
                $counts = $this->syncWindow(
                    $leadService,
                    $pullLeadAction,
                    $company,
                    $crmId,
                    $windowStart,
                    $windowEnd
                );
            } catch (Throwable $e) {
                $this->error("  {$windowStart} → {$windowEnd}: failed — " . $e->getMessage());

                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $counts[$key];
            }

            $this->line("  {$windowStart} → {$windowEnd}: scanned {$counts['scanned']}, matched {$counts['matched']}, synced {$counts['synced']}, errors {$counts['errors']}");
        }

        $this->newLine();
        $this->info("Done. Scanned {$totals['scanned']} deals, {$totals['matched']} belonged to this user, {$totals['synced']} synced, {$totals['errors']} errors.");
    }

    /**
     * @return array{scanned: int, matched: int, synced: int, errors: int}
     */
    private function syncWindow(
        LeadService $leadService,
        PullLeadAction $pullLeadAction,
        Companies $company,
        string $crmId,
        string $startDate,
        string $endDate
    ): array {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['scanned' => 0, 'matched' => 0, 'synced' => 0, 'errors' => 0];
        $offset = 0;

        while (true) {
            $response = $leadService->getDealsByRange(
                $startDate,
                $endDate,
                $offset,
                includeMeta: true
            );

            $deals = $response['data'] ?? [];
            $meta = $response['meta'] ?? [];

            if (empty($deals)) {
                break;
            }

            foreach ($deals as $deal) {
                $counts['scanned']++;

                if (LeadUserService::extractSalespersonCrmId($deal) !== $crmId) {
                    continue;
                }

                $counts['matched']++;
                $dealId = LeadUserService::extractDealIdentifier($deal);

                if (! $dealId) {
                    $this->warn('  Matching deal has no CrmId, skipping...');
                    $counts['errors']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] deal {$dealId} — " . ($deal['pipelineStage'] ?? $deal['stage'] ?? 'Undefined'));

                    continue;
                }

                try {
                    Cache::lock("drivecentric_deal_sync:{$company->getId()}:{$dealId}", 10)
                        ->block(10, function () use ($pullLeadAction, $deal, &$counts): void {
                            $lead = $pullLeadAction->syncDeal($deal);
                            $lead->set('downloaded_from_drivecentric', 1);
                            new ApplyLeadClosingStatusAction($lead)->execute();
                            $counts['synced']++;
                        });
                } catch (Throwable $e) {
                    $counts['errors']++;
                    $this->warn("  Failed to sync deal {$dealId}: " . $e->getMessage());
                }
            }

            if (($meta['next'] ?? null) === null) {
                break;
            }

            $offset = ($meta['offset'] ?? $offset) + count($deals);
        }

        return $counts;
    }
}
