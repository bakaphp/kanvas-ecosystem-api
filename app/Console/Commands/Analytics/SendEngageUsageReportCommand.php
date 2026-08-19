<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Kanvas\Analytics\Actions\BuildEngagementLeaderboardAction;
use Kanvas\Analytics\Actions\SendEngageUsageReportAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Enums\MessageChannelEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

/**
 * Weekly Engage usage report fan-out. Walks every app with `engage_usage_report_enabled` set and
 * mails each of its active companies a per-rep leaderboard for the last seven complete days.
 *
 * The window is resolved per company timezone, so a Monday-morning cron gives every tenant its own
 * Mon–Sun week rather than a UTC slice of one.
 */
class SendEngageUsageReportCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:analytics:send-engage-usage-report
        {--app= : Restrict to a single apps_id (bypasses the engage_usage_report_enabled gate)}
        {--company= : Restrict to a single companies_id. On its own it bypasses the enabled gate.}
        {--from= : Range start (Y-m-d). Requires --to. Defaults to the last 7 complete days.}
        {--to= : Range end (Y-m-d). Requires --from.}
        {--channel=all : sms, email, or all}
        {--dry-run : Build the leaderboard and print it without sending any email}';

    protected $description = 'Email the weekly Engage usage leaderboard to each company\'s managers.';

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

        $apps = $this->resolveApps();
        if ($apps === []) {
            $this->info('No apps have engage_usage_report_enabled set — nothing to send.');

            return self::SUCCESS;
        }

        $sentTotal = 0;
        $companiesProcessed = 0;
        $failed = 0;

        foreach ($apps as $app) {
            $this->info(sprintf('App %d - %s', $app->getId(), $app->name));
            $this->overwriteAppService($app);

            foreach ($this->companiesFor($app) as $company) {
                $companiesProcessed++;

                try {
                    $sentTotal += $this->reportFor($app, $company, $channel);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error(sprintf('  company=%d failed: %s', $company->getId(), $e->getMessage()));
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

        $sent = new SendEngageUsageReportAction(
            app: $app,
            company: $company,
            request: $request,
            channel: $channel,
        )->execute();

        $this->line(sprintf('  company=%-6d %-40s recipients=%d', $company->getId(), $company->name, $sent));

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
     * @return array<int, Apps>
     */
    private function resolveApps(): array
    {
        if ($this->option('app') !== null) {
            return [Apps::getById((int) $this->option('app'))];
        }

        // --company alone is an explicit "run it for this tenant": no enabled gate, and the
        // operator shouldn't need to know which app it lives under.
        if ($this->option('company') !== null) {
            return $this->appsForCompany((int) $this->option('company'));
        }

        return Apps::disableCache()
            ->notDeleted()
            ->get()
            ->filter(fn (Apps $app): bool => (bool) $app->get('engage_usage_report_enabled'))
            ->values()
            ->all();
    }

    /**
     * A company can belong to more than one app, so this returns all of them rather than guessing.
     *
     * @return array<int, Apps>
     */
    private function appsForCompany(int $companyId): array
    {
        $appIds = UserCompanyApps::query()
            ->where('companies_id', $companyId)
            ->where('is_deleted', 0)
            ->distinct()
            ->pluck('apps_id');

        if ($appIds->isEmpty()) {
            $this->warn(sprintf('Company %d is not registered under any app.', $companyId));

            return [];
        }

        return Apps::query()
            ->whereIn('id', $appIds)
            ->notDeleted()
            ->get()
            ->all();
    }

    /**
     * @return iterable<Companies>
     */
    private function companiesFor(Apps $app): iterable
    {
        if ($this->option('company') !== null) {
            return [Companies::getById((int) $this->option('company'))];
        }

        return AppsRepository::getActiveCompaniesForAppBuilder($app)->cursor();
    }
}
