<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Jobs\CreateAgentConfigBackupJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class DailyAgentConfigBackupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:daily-agent-config-backup
        {--agent= : Restrict to a single agent id}
        {--app= : Restrict to a single app id}';

    protected $description = 'Dispatch end-of-day config backups for all active agents to S3.';

    public function handle(): int
    {
        $query = Agent::query()
            ->where('is_active', 1)
            ->where('is_deleted', 0);

        if ($this->option('agent') !== null) {
            $query->where('id', (int) $this->option('agent'));
        }

        if ($this->option('app') !== null) {
            $query->where('apps_id', (int) $this->option('app'));
        }

        $dispatched = 0;
        $failed = 0;

        $query->chunkById(50, function ($agents) use (&$dispatched, &$failed): void {
            foreach ($agents as $agent) {
                /** @var Apps $app */
                try {
                    $app = Apps::find($agent->apps_id);

                    if ($app === null) {
                        continue;
                    }

                    CreateAgentConfigBackupJob::dispatch($agent, $app, 'daily-auto-backup');
                    $dispatched++;
                } catch (Throwable $e) {
                    $this->error("Agent {$agent->id}: " . $e->getMessage());
                    $failed++;
                }
            }
        });

        $this->info("Dispatched: {$dispatched} | Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
