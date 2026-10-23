<?php

declare(strict_types=1);

namespace App\Console\Commands\Lead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpActivityService;

/**
 * `kanvas:status` for follow-ups: what each company sent and what is failing, from the ledger. The
 * queue line is global — `failed_jobs` rows cannot be told apart by company — while the per-company
 * `Failed` column counts `lead.follow_up.failed`, which a job writes when its last try fails.
 */
class LeadFollowUpStatusCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'lead:follow-up-status
        {--app_id= : Only this app}
        {--company_id= : Only this company, with its failure detail}
        {--hours=24 : How far back to look}
        {--failures=10 : Latest failures listed with --company_id}';

    protected $description = 'Follow-up v2 health per company: queued, sent, skipped, failed and exhausted from the ledger.';

    private const string QUEUE = 'lead_follow_ups';

    public function handle(LeadFollowUpActivityService $activity): int
    {
        $companyId = filled($this->option('company_id')) ? (int) $this->option('company_id') : null;
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);

        $this->reportQueue();

        // A named app is shown even before it turns v2 on, so its ledger can be checked first.
        $apps = filled($this->option('app_id'))
            ? Apps::query()->where('id', (int) $this->option('app_id'))->get()
            : Apps::query()->get()->filter(fn (Apps $app) => (bool) $app->get('use_lead_follow_up_v2'));

        $failing = false;
        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            $counts = $activity->countsByCompany($app, $since, $companyId);
            $this->newLine();
            $this->line("<options=bold>{$app->name}</> (app {$app->getId()}) — last {$hours}h");

            if ($counts === []) {
                $this->line('  No follow-up activity.');

                continue;
            }

            $failing = $this->reportCompanies($counts) || $failing;

            if ($companyId !== null) {
                $this->reportFailures($activity->recentFailures(
                    $app,
                    $since,
                    $companyId,
                    max(1, (int) $this->option('failures'))
                ));
            }
        }

        $this->newLine();
        $failing
            ? $this->warn('  ! Some companies have failing follow-ups. Run with --company_id=<id> for the detail.')
            : $this->info('  ✓ No failing follow-ups.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function reportQueue(): void
    {
        $failed = DB::connection(Config::get('queue.failed.database'))
            ->table(Config::get('queue.failed.table', 'failed_jobs'))
            ->where('queue', self::QUEUE)
            ->count();

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Queue %s</> (all companies): waiting %d | failed jobs %s',
            self::QUEUE,
            Queue::size(self::QUEUE),
            $failed > 0 ? "<fg=red>{$failed}</>" : '0'
        ));
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

        foreach ($counts as $id => $company) {
            arsort($company['failed_by_reason']);
            $failing = $failing || $company['failed'] > 0;

            $rows[] = [
                $id,
                $names[$id] ?? '-',
                $company['queued'],
                $company['sent'],
                $company['skipped'],
                $company['failed'] > 0 ? "<fg=red>{$company['failed']}</>" : '0',
                $company['exhausted'],
                $company['last_sent_at'] ? Carbon::parse($company['last_sent_at'])->diffForHumans() : '-',
                implode(', ', array_map(
                    fn (string $reason, int $total) => "{$reason} ({$total})",
                    array_keys($company['failed_by_reason']),
                    $company['failed_by_reason']
                )) ?: '-',
            ];
        }

        $this->table(['ID', 'Company', 'Queued', 'Sent', 'Skipped', 'Failed', 'Exhausted', 'Last sent', 'Failures'], $rows);

        return $failing;
    }

    /**
     * @param list<array{company_id: int, lead_id: int, reason: string, channel: ?string, error: ?string, occurred_at: string}> $failures
     */
    private function reportFailures(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $this->line('<options=bold>Latest failures</>');
        $this->table(
            ['Lead', 'Reason', 'Channel', 'Error', 'When'],
            array_map(fn (array $failure) => [
                $failure['lead_id'],
                $failure['reason'],
                $failure['channel'] ?? '-',
                mb_strimwidth((string) $failure['error'], 0, 80, '...'),
                Carbon::parse($failure['occurred_at'])->diffForHumans(),
            ], $failures)
        );
    }
}
