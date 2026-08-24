<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class KanvasStatusCommand extends Command
{
    protected $signature = 'kanvas:status {--backlog=10000 : Pending count above which a queue is flagged as backed up}';

    protected $description = 'Health snapshot of Kanvas: databases, redis, queues, failed jobs — is everything good?';

    private const array QUEUES = [
        'default',
        'kanvas-social',
        'notifications',
        'user-interactions',
        'message',
        'batch-logger',
        'imports',
        'scout',
        'scrapper-queue',
        'sync-shopify-queue',
        'workflow',
        'broadcasts',
        'ledger',
        'agent-runtime',
        'agent-chat',
        'nervous-system-project',
        'scheduled-actions',
        'slack-ingest',
        'product-enrichment',
        'product-discovery',
        'scribe-aging',
        'scribe-pdf-ingest',
        'lead_follow_ups',
    ];

    private const array DATABASE_CONNECTIONS = [
        'mysql',
        'ecosystem',
        'inventory',
        'social',
        'crm',
        'content_engine',
        'workflow',
        'action_engine',
        'commerce',
        'event',
        'intelligence',
        'accounting',
    ];

    public function handle(QueueFactory $queue): int
    {
        $healthy = true;

        $healthy = $this->reportDatabases() && $healthy;
        $healthy = $this->reportRedis() && $healthy;

        $backlogged = $this->reportQueues($queue, (int) $this->option('backlog'));

        $this->newLine();
        if (! $healthy) {
            $this->error('  ✗ ISSUES DETECTED — a database or redis connection is down. See above.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($backlogged) {
            $this->warn('  ! Infrastructure healthy, but some queues are backed up or have failed jobs. See above.');
        } else {
            $this->info('  ✓ ALL GOOD — every connection is up and queues are draining.');
        }
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Ping every per-domain database connection with a trivial query.
     */
    private function reportDatabases(): bool
    {
        $healthy = true;
        $rows = [];

        foreach (self::DATABASE_CONNECTIONS as $name) {
            $start = microtime(true);

            try {
                DB::connection($name)->select('SELECT 1');
                $ms = (int) round((microtime(true) - $start) * 1000);
                $rows[] = [$name, '<fg=green>up</>', "{$ms} ms"];
            } catch (Throwable $e) {
                $healthy = false;
                $rows[] = [$name, '<fg=red>DOWN</>', $this->shorten($e->getMessage())];
            }
        }

        $this->newLine();
        $this->line('<options=bold>Databases</>');
        $this->table(['Connection', 'Status', 'Latency / error'], $rows);

        return $healthy;
    }

    private function reportRedis(): bool
    {
        $start = microtime(true);

        try {
            Redis::connection()->ping();
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->line("<options=bold>Redis</>: <fg=green>up</> ({$ms} ms)");

            return true;
        } catch (Throwable $e) {
            $this->line('<options=bold>Redis</>: <fg=red>DOWN</> — ' . $this->shorten($e->getMessage()));

            return false;
        }
    }

    /**
     * @return bool whether any queue is backed up or carrying failed jobs
     */
    private function reportQueues(QueueFactory $queue, int $backlogThreshold): bool
    {
        $failedByQueue = $this->failedCountsByQueue();
        $totalFailed = array_sum($failedByQueue);

        $totalPending = 0;
        $backlogged = false;
        $rows = [];
        foreach (self::QUEUES as $name) {
            $pending = $queue->connection()->size($name);
            $failed = $failedByQueue[$name] ?? 0;
            $totalPending += $pending;

            $overThreshold = $pending >= $backlogThreshold;
            $backlogged = $backlogged || $overThreshold || $failed > 0;

            $rows[] = [
                $name,
                $overThreshold ? "<fg=yellow>{$pending}</>" : (string) $pending,
                $failed > 0 ? "<fg=red>{$failed}</>" : '0',
            ];
        }

        $this->newLine();
        $this->line('<options=bold>Queues</>');
        $this->table(['Queue', 'Pending', 'Failed'], $rows);
        $this->line('  Total pending: ' . $totalPending . '   |   Total failed: ' . $totalFailed);

        return $backlogged || $totalFailed > 0;
    }

    /**
     * Count failed jobs grouped by queue with a single aggregate query.
     *
     * Never load the rows themselves — each carries a full serialized payload
     * and exception trace, so `SELECT *` on a large failed_jobs table exhausts
     * memory just to count them.
     *
     * @return array<string, int> queue name => failed count
     */
    private function failedCountsByQueue(): array
    {
        $connection = Config::get('queue.failed.database');
        $table = Config::get('queue.failed.table', 'failed_jobs');

        try {
            return DB::connection($connection)
                ->table($table)
                ->selectRaw('queue, COUNT(*) as aggregate')
                ->groupBy('queue')
                ->pluck('aggregate', 'queue')
                ->map(fn ($count) => (int) $count)
                ->all();
        } catch (Throwable $e) {
            $this->warn('  ! Could not read failed jobs: ' . $this->shorten($e->getMessage()));

            return [];
        }
    }

    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return strlen($message) > 80 ? substr($message, 0, 77) . '...' : $message;
    }
}
