<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Capability\Actions\ReconcileToolKanvasModulesAction;
use Kanvas\NervousSystem\Capability\Models\Tool;

class SyncToolKanvasModulesCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:sync-tool-modules
        {--tool= : Sync a single tool by id (otherwise all tools with a handler)}';

    protected $description = 'Mirror tool handlers\' kanvasModules() declarations into the pivot.';

    public function handle(): int
    {
        $query = Tool::query()
            ->whereNotNull('handler')
            ->where('is_deleted', 0);

        if ($this->option('tool') !== null) {
            $query->where('id', (int) $this->option('tool'));
        }

        $totals = ['added' => 0, 'updated' => 0, 'removed' => 0, 'tools_touched' => 0, 'tools_skipped' => 0];

        $query->chunkById(200, function ($tools) use (&$totals): void {
            foreach ($tools as $tool) {
                $result = new ReconcileToolKanvasModulesAction($tool)->execute();

                if ($result === ['added' => 0, 'updated' => 0, 'removed' => 0]) {
                    $totals['tools_skipped']++;

                    continue;
                }

                $totals['added'] += $result['added'];
                $totals['updated'] += $result['updated'];
                $totals['removed'] += $result['removed'];
                $totals['tools_touched']++;

                $this->line(sprintf(
                    '  tool id=%d (%s): +%d updated=%d -%d',
                    $tool->getId(),
                    $tool->name,
                    $result['added'],
                    $result['updated'],
                    $result['removed'],
                ));
            }
        });

        $this->info(sprintf(
            'Done. Tools touched: %d · skipped (no contract or no handler): %d · pivot rows added=%d updated=%d removed=%d',
            $totals['tools_touched'],
            $totals['tools_skipped'],
            $totals['added'],
            $totals['updated'],
            $totals['removed'],
        ));

        return self::SUCCESS;
    }
}
