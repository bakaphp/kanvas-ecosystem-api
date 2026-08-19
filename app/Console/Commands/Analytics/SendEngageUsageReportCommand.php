<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Kanvas\Analytics\Actions\BuildEngagementLeaderboardAction;
use Kanvas\Analytics\Actions\SendEngageUsageReportAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings as AppsSettings;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

/**
 * Weekly Engage usage report fan-out. Mails a per-rep leaderboard for the last seven complete days
 * to every company that has `engage_usage_report_enabled` set, inside an app that has it set too.
 * Both levels must be on: the app switch turns the module on for a product, the company switch
 * opts an individual tenant in, and either can turn it back off alone.
 *
 * The window is resolved per company timezone, so a Monday-morning cron gives every tenant its own
 * Mon–Sun week rather than a UTC slice of one.
 */
class SendEngageUsageReportCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:analytics:send-engage-usage-report
        {--app= : Restrict to a single apps_id. Stands in for the app-level enabled flag.}
        {--company= : Restrict to a single companies_id. Bypasses the per-company enabled gate.}
        {--from= : Range start (Y-m-d). Requires --to. Defaults to the last 7 complete days.}
        {--to= : Range end (Y-m-d). Requires --from.}
        {--channel=all : sms, email, or all}
        {--email= : Comma-separated address(es) to send to instead of the company\'s managers}
        {--dry-run : Build the leaderboard and print it without sending any email}';

    protected $description = 'Email the weekly Engage usage leaderboard to each company\'s managers.';

    /** Opt-in flag; must be set on BOTH the app and the company. */
    private const string ENABLED_SETTING = 'engage_usage_report_enabled';

    public function handle(): int
    {
        $channel = MessageChannelEnum::tryFrom(strtolower((string) $this->option('channel')));
        if ($channel === null) {
            $this->error('Invalid --channel. Use one of: ' . implode(', ', MessageChannelEnum::values()) . '.');

            return self::FAILURE;
        }

        $from = $this->option('from');
        $to = $this->option('to');
        if (($from === null) !== ($to === null)) {
            $this->error('--from and --to must be given together.');

            return self::FAILURE;
        }

        $tenants = $this->resolveTenants();
        if ($tenants === []) {
            $this->info('Nothing to send.');

            return self::SUCCESS;
        }

        $sentTotal = 0;
        $companiesProcessed = 0;
        $failed = 0;

        foreach ($tenants as $appId => $companyIds) {
            $app = Apps::getById($appId);
            $this->info(sprintf('App %d - %s', $app->getId(), $app->name));
            $this->overwriteAppService($app);

            foreach ($companyIds as $companyId) {
                $companiesProcessed++;

                try {
                    $sentTotal += $this->reportFor($app, Companies::getById($companyId), $channel);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  company=%d failed: %s', $companyId, $e->getMessage()));
                }
            }
        }

        $this->info(sprintf(
            'Done. %d email(s) across %d company/companies. Failures: %d',
            $sentTotal,
            $companiesProcessed,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function reportFor(Apps $app, Companies $company, MessageChannelEnum $channel): int
    {
        $request = AnalyticsRequest::fromGraphQL($this->rangeArgs($company), $company);

        if ($this->option('dry-run')) {
            $this->printDryRun($app, $company, $request, $channel);

            return 0;
        }

        $overrideEmails = $this->overrideEmails();

        $sent = new SendEngageUsageReportAction(
            app: $app,
            company: $company,
            request: $request,
            channel: $channel,
            overrideEmails: $overrideEmails,
        )->execute();

        $this->line(sprintf(
            '  company=%-6d %-40s sent=%d%s',
            $company->getId(),
            $company->name,
            $sent,
            $overrideEmails === [] ? '' : ' -> ' . implode(', ', $overrideEmails),
        ));

        return $sent;
    }

    private function printDryRun(
        Apps $app,
        Companies $company,
        AnalyticsRequest $request,
        MessageChannelEnum $channel,
    ): void {
        $result = new BuildEngagementLeaderboardAction(
            app: $app,
            company: $company,
            request: $request,
            channel: $channel,
        )->execute();

        $this->line(sprintf(
            '  company=%-6d %s (%s → %s)',
            $company->getId(),
            $company->name,
            $request->from->toDateString(),
            $request->to->toDateString(),
        ));

        if ($result['rows'] === []) {
            $this->line('    no activity');

            return;
        }

        $this->table(
            ['Rep', 'Total', 'AI', 'Rep', 'Resp(s)', 'Replies', 'Appts'],
            array_map(static fn (array $row): array => [
                $row['name'],
                $row['total_sent'],
                $row['ai_sent'],
                $row['rep_sent'],
                $row['median_response_seconds'] ?? '—',
                $row['replies'],
                $row['appointments'],
            ], $result['rows']),
        );
    }

    /**
     * Last seven complete days in the company's timezone. The cron runs in the morning, so
     * "yesterday" is the newest day with a full set of data.
     *
     * @return array{from: string, to: string, bucket: string, timezone: string}
     */
    private function rangeArgs(Companies $company): array
    {
        $timezone = (string) ($company->timezone ?? '') ?: (string) (config('app.timezone') ?? 'UTC');

        $from = $this->option('from');
        $to = $this->option('to');

        if ($from !== null && $to !== null) {
            return [
                'from' => (string) $from,
                'to' => (string) $to,
                'bucket' => 'DAY',
                'timezone' => $timezone,
            ];
        }

        $yesterday = Date::now($timezone)->subDay();

        return [
            'from' => $yesterday->copy()->subDays(6)->format('Y-m-d'),
            'to' => $yesterday->format('Y-m-d'),
            'bucket' => 'DAY',
            'timezone' => $timezone,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function overrideEmails(): array
    {
        $to = trim((string) $this->option('email'));

        if ($to === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $to))));
    }

    /**
     * The (app, company) pairs to report on, keyed by apps_id.
     *
     * Driven by the opted-in companies rather than by walking every app: opt-in is per company, and
     * iterating all apps would touch hundreds of tenants to find a handful.
     *
     * @return array<int, array<int, int>>
     */
    private function resolveTenants(): array
    {
        $companyId = $this->option('company');

        if ($companyId !== null) {
            $tenants = [];
            foreach ($this->appIdsForCompany((int) $companyId) as $appId) {
                $tenants[$appId] = [(int) $companyId];
            }

            return $tenants;
        }

        $companies = $this->enabledIds(CompaniesSettings::query(), 'companies_id');
        if ($companies === []) {
            $this->info(sprintf('No company has %s set.', self::ENABLED_SETTING));

            return [];
        }

        // --app is an explicit target and stands in for the app-level switch; otherwise both
        // levels have to be on for a tenant to receive the report.
        $appId = $this->option('app');
        $apps = $appId !== null ? [(int) $appId] : $this->enabledIds(AppsSettings::query(), 'apps_id');

        if ($apps === []) {
            $this->info(sprintf('No app has %s set.', self::ENABLED_SETTING));

            return [];
        }

        $rows = UserCompanyApps::query()
            ->whereIn('companies_id', $companies)
            ->whereIn('apps_id', $apps)
            ->where('is_deleted', 0)
            ->distinct()
            ->get(['apps_id', 'companies_id']);

        $tenants = [];
        foreach ($rows as $row) {
            $tenants[(int) $row->apps_id][] = (int) $row->companies_id;
        }

        return $tenants;
    }

    /**
     * Owner ids that switched the report on, read straight off the settings table — the
     * alternative is one $model->get() query per app and per company.
     *
     * @param  Builder<CompaniesSettings|AppsSettings>  $query
     * @return array<int, int>
     */
    private function enabledIds(Builder $query, string $ownerColumn): array
    {
        return $query
            ->where('name', self::ENABLED_SETTING)
            // HashTableTrait upserts settings without touching is_deleted, so rows it writes carry
            // NULL rather than 0 — a plain `where('is_deleted', 0)` misses them.
            ->where(fn (Builder $scoped) => $scoped->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->pluck('value', $ownerColumn)
            ->filter(fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL))
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * A company can belong to more than one app, so this returns all of them rather than guessing.
     *
     * @return array<int, int>
     */
    private function appIdsForCompany(int $companyId): array
    {
        $appIds = UserCompanyApps::query()
            ->where('companies_id', $companyId)
            ->where('is_deleted', 0)
            ->distinct()
            ->pluck('apps_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($appIds === []) {
            $this->warn(sprintf('Company %d is not registered under any app.', $companyId));
        }

        return $appIds;
    }
}
