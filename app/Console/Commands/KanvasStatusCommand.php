<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class KanvasStatusCommand extends Command
{
    protected $signature = 'kanvas:status {--backlog=10000 : Pending count above which a queue is flagged as backed up}';

    protected $description = 'Health snapshot of Kanvas: databases, redis, queues, failed jobs — is everything good?';

    private const QUEUES = [
        'default',
        'kanvas-social',
        'notifications',
        'user-interactions',
        'batch-logger',
        'imports',
        'scout',
        'scrapper-queue',
        'sync-shopify-queue',
        'workflow',
        'agent-runtime',
        'ledger',
        'scribe-aging',
        'scribe-pdf-ingest',
        'lead_follow_ups',
    ];

    private const DATABASE_CONNECTIONS = [
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

    public function handle(
        QueueFactory $queue,
        FailedJobProviderInterface $failer
    ): int {
        $healthy = true;

        $healthy = $this->reportDatabases() && $healthy;
        $healthy = $this->reportRedis() && $healthy;

        $backlogged = $this->reportQueues($queue, $failer, (int) $this->option('backlog'));

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
    private function reportQueues(QueueFactory $queue, FailedJobProviderInterface $failer, int $backlogThreshold): bool
    {
        $failedJobs = $failer->all();
        $totalFailed = count($failedJobs);

        $failedByQueue = [];
        foreach ($failedJobs as $job) {
            $failedByQueue[$job->queue] = ($failedByQueue[$job->queue] ?? 0) + 1;
        }

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

    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return strlen($message) > 80 ? substr($message, 0, 77) . '...' : $message;
    }
}
