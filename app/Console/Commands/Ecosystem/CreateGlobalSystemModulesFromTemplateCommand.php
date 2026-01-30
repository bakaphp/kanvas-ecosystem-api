<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\SystemModules\Models\SystemModules;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class CreateGlobalSystemModulesFromTemplateCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-global-system-modules-from-template';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create global system modules based on ModulesRepositories template with their corresponding modules_id';

    public function handle(): int
    {
        $appId = 0;
        $verbose = $this->option('verbose');
        $abilitiesByModule = ModulesRepositories::getAbilitiesByModule();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($abilitiesByModule as $moduleId => $systemModules) {
            info("Processing module ID: {$moduleId}");

            foreach ($systemModules as $modelClass => $abilities) {
                if (! class_exists($modelClass)) {
                    warning("Class does not exist: {$modelClass} - Skipping");
                    $skippedCount++;

                    continue;
                }

                // Extract the model name from the full class path
                $modelParts = explode('\\', $modelClass);
                $modelName = end($modelParts);

                if ($verbose) {
                    $this->line("  → Checking: {$modelName} ({$modelClass})");
                }

                // Check if system module already exists (for global apps_id = 0)
                $systemModule = SystemModules::where('model_name', $modelClass)
                    ->where('apps_id', $appId)
                    ->first();

                // Show if there are system modules with other apps_id
                if (! $systemModule && $verbose) {
                    $otherAppModules = SystemModules::where('model_name', $modelClass)
                        ->where('apps_id', '!=', $appId)
                        ->get();

                    if ($otherAppModules->isNotEmpty()) {
                        $this->line("    ℹ Found {$otherAppModules->count()} record(s) with different apps_id: " .
                            $otherAppModules->pluck('apps_id')->implode(', '));
                    }
                }

                if ($systemModule) {
                    if ($verbose) {
                        $this->line("    Found existing record (ID: {$systemModule->id}, current modules_id: " . ($systemModule->modules_id ?? 'NULL') . ')');
                    }

                    // Update modules_id if it's different or null
                    if ($systemModule->modules_id !== $moduleId) {
                        $oldModuleId = $systemModule->modules_id ?? 'NULL';

                        $systemModule->modules_id = $moduleId;
                        $systemModule->save();

                        info("    ✓ Updated - {$modelName} - modules_id: {$oldModuleId} → {$moduleId}");
                        $updatedCount++;
                    } else {
                        if ($verbose) {
                            $this->line("    ✓ Already correct (modules_id: {$moduleId})");
                        }
                        $skippedCount++;
                    }
                } else {
                    // Create new system module
                    $systemModule = SystemModules::create([
                        'model_name' => $modelClass,
                        'name' => Str::simpleSlug($modelName),
                        'apps_id' => $appId,
                        'slug' => Str::simpleSlug($modelName),
                        'modules_id' => $moduleId,
                        'use_import' => false,
                    ]);

                    info("    ✓ Created - {$modelName} - modules_id: {$moduleId}");
                    $createdCount++;
                }
            }
        }

        $this->newLine();
        info('Summary:');
        info("- Created: {$createdCount}");
        info("- Updated: {$updatedCount}");
        info("- Skipped: {$skippedCount}");
        info('Total processed: ' . ($createdCount + $updatedCount + $skippedCount));

        return self::SUCCESS;
    }
}
