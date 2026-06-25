<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Jobs\CreateAgentConfigBackupJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class DailyAgentConfigBackupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:daily-agent-config-backup
        {--agent= : Restrict to a single agent id}
        {--app= : Restrict to a single app id}';

    protected $description = 'Dispatch end-of-day config backups for active agents whose company local time is 23:xx.';

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
                try {
                    $app = Apps::find($agent->apps_id);

                    if ($app === null) {
                        continue;
                    }

                    // Only dispatch when it is 23:xx in the company's local timezone.
                    // The command runs hourly so each company gets its backup at its own EOD.
                    $company = Companies::find($agent->companies_id);
                    $timezone = $company?->timezone ?? 'UTC';

                    if (now()->setTimezone($timezone)->hour !== 23) {
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
