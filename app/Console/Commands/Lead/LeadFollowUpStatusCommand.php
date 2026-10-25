<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpActivityService;

/**
 * `kanvas:status` for follow-ups: what each company sent and what is failing in a time window, from the
 * ledger, with the same detail per company as the daily summary email. The queue line is global —
 * `failed_jobs` rows cannot be told apart by company — while the per-company `Failed` column counts
 * `lead.follow_up.failed`, which a job writes when its last try fails.
 */
class LeadFollowUpStatusCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'lead:follow-up-status
        {--app_id= : Only this app}
        {--company_id= : Only this company}
        {--from=08:00 : Window start, a time today or a full date-time}
        {--to= : Window end, default now}
        {--hours= : Instead of --from, the last N hours}
        {--tz= : Timezone for the window, default the company\'s with --company_id, else UTC}
        {--failures=10 : Latest failures listed per company}';

    protected $description = 'Follow-up v2 health per company: queued, sent, skipped, failed and exhausted from the ledger, with the daily summary detail.';

    private const string QUEUE = 'lead_follow_ups';

    private const int TOP_JOB_ERRORS = 5;

    public function handle(LeadFollowUpActivityService $activity): int
    {
        $appId = $this->option('app_id');
        $companyId = filled($this->option('company_id')) ? (int) $this->option('company_id') : null;
        $tz = $this->option('tz')
            ?: ($companyId !== null ? Companies::getById($companyId)->getTimezone() : null)
            ?: Config::get('app.timezone');
        [$from, $to] = $this->window($tz);

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Window</> %s → %s %s (%s)',
            $from->format('Y-m-d H:i'),
            $to->format($from->isSameDay($to) ? 'H:i' : 'Y-m-d H:i'),
            $tz,
            $from->diffForHumans($to, syntax: Carbon::DIFF_ABSOLUTE, parts: 2)
        ));
        $this->reportQueue($from, $to);

        // A named app is shown even before it turns v2 on, so its ledger can be checked first.
        $apps = filled($appId)
            ? Apps::query()->where('id', (int) $appId)->get()
            : Apps::query()->get()->filter(fn (Apps $app) => (bool) $app->get('use_lead_follow_up_v2'));

        $failing = false;
        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            $counts = $activity->countsByCompany(
                $app,
                $from->copy()->utc(),
                $to->copy()->utc(),
                $companyId
            );
            $this->newLine();
            $this->line("<options=bold>{$app->name}</> (app {$app->getId()})");

            if ($counts === []) {
                $this->line('  No follow-up activity.');

                continue;
            }

            $failing = $this->reportCompanies($counts) || $failing;

            $companies = Companies::query()->whereIn('id', array_keys($counts))->get();
            foreach ($companies as $company) {
                $this->reportCompanyDetail(
                    $company,
                    $activity->summarize(
                        $app,
                        $company,
                        $from->copy()->utc(),
                        $to->copy()->utc(),
                        max(1, (int) $this->option('failures'))
                    ),
                    $tz
                );
            }
        }

        $this->newLine();
        $failing
            ? $this->warn('  ! Some companies have failing follow-ups.')
            : $this->info('  ✓ No failing follow-ups.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $tz): array
    {
        $to = filled($this->option('to')) ? Carbon::parse($this->option('to'), $tz) : Carbon::now($tz);
        $from = filled($this->option('hours'))
            ? $to->copy()->subHours(max(1, (int) $this->option('hours')))
            : Carbon::parse((string) $this->option('from'), $tz);

        // Before 08:00 the default start is still ahead today, so the window is since yesterday's.
        if ($from->gte($to)) {
            $from->subDay();
        }

        return [$from, $to];
    }

    private function reportQueue(Carbon $from, Carbon $to): void
    {
        $failedJobs = fn (): Builder => DB::connection(Config::get('queue.failed.database'))
            ->table(Config::get('queue.failed.table', 'failed_jobs'))
            ->where('queue', self::QUEUE);
        $failed = $failedJobs()->count();
        $inWindow = $failedJobs()->whereBetween('failed_at', [$from->copy()->utc(), $to->copy()->utc()]);

        $this->line(sprintf(
            '<options=bold>Queue %s</> (all companies): waiting %d | failed jobs %s, %s in window',
            self::QUEUE,
            Queue::size(self::QUEUE),
            $failed > 0 ? "<fg=red>{$failed}</>" : '0',
            $inWindow->clone()->count()
        ));

        $errors = $inWindow
            ->selectRaw("SUBSTRING_INDEX(exception, '\\n', 1) AS error, COUNT(*) AS total, MAX(failed_at) AS last_at")
            ->groupBy('error')
            ->orderByDesc('total')
            ->limit(self::TOP_JOB_ERRORS)
            ->get();

        if ($errors->isNotEmpty()) {
            $this->table(
                ['Failed jobs', 'Error', 'Last'],
                $errors->map(fn (object $row) => [
                    $row->total,
                    mb_strimwidth((string) $row->error, 0, 110, '...'),
                    Carbon::parse($row->last_at)->diffForHumans(),
                ])->all()
            );
        }
    }

    /**
     * @param array<int, array{queued: int, sent: int, skipped: int, failed: int, exhausted: int, last_sent_at: ?string, failed_by_reason: array<string, int>}> $counts
     * @return bool whether any company has failures
     */
    private function reportCompanies(array $counts): bool
    {
        $names = Companies::query()->whereIn('id', array_keys($counts))->pluck('name', 'id');
        $failing = false;
        $rows = [];

        foreach ($counts as $id => $count) {
            $failing = $failing || $count['failed'] > 0;

            $rows[] = [
                $id,
                $names[$id] ?? '-',
                $count['queued'],
                $count['sent'],
                $count['skipped'],
                $count['failed'] > 0 ? "<fg=red>{$count['failed']}</>" : '0',
                $count['exhausted'],
                $count['last_sent_at'] ? Carbon::parse($count['last_sent_at'])->diffForHumans() : '-',
            ];
        }

        $this->table(['ID', 'Company', 'Queued', 'Sent', 'Skipped', 'Failed', 'Exhausted', 'Last sent'], $rows);

        return $failing;
    }

    /**
     * @param array<string, mixed> $summary the `LeadFollowUpActivityService::summarize()` shape the email renders
     */
    private function reportCompanyDetail(
        Companies $company,
        array $summary,
        string $tz
    ): void {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s</> (company %d): sent %d | failed %s | queued %d | exhausted %d | skipped %d | resumed %d | completed %d',
            $company->name,
            $company->getId(),
            $summary['sent']['total'],
            $summary['failed']['total'] > 0 ? "<fg=red>{$summary['failed']['total']}</>" : '0',
            $summary['queued']['total'],
            $summary['exhausted']['total'],
            $summary['skipped']['total'],
            $summary['resumed']['total'],
            $summary['completed']['total'],
        ));

        $this->breakdown('Sent by channel', $summary['sent']['by_channel']);
        $this->breakdown('Failures by reason', $summary['failed']['by_reason']);
        $this->breakdown('Skipped by reason', $summary['skipped']['by_reason']);
        $this->breakdown('Exhausted by reason', $summary['exhausted']['by_reason']);

        if ($summary['failed']['recent'] === []) {
            return;
        }

        $this->table(
            ['Lead', 'Title', 'Reason', 'Channel', 'Error', 'When'],
            array_map(fn (array $failure) => [
                $failure['lead_id'],
                mb_strimwidth((string) $failure['lead_title'], 0, 30, '...'),
                $failure['reason'],
                $failure['channel'] ?? '-',
                mb_strimwidth((string) $failure['error'], 0, 80, '...'),
                Carbon::parse($failure['occurred_at'])->setTimezone($tz)->format('M j H:i'),
            ], $summary['failed']['recent'])
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private function breakdown(string $label, array $counts): void
    {
        if ($counts === []) {
            return;
        }

        arsort($counts);
        $this->line(sprintf(
            '  %s: %s',
            $label,
            implode(', ', array_map(
                fn (string $key, int $total) => "{$key} ({$total})",
                array_keys($counts),
                $counts
            ))
        ));
    }
}
