<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use Baka\Search\AlgoliaSettingsReconciler;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Throwable;

class AlgoliaSyncSettingsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:search:algolia-sync-settings
                            {model : FQCN of the searchable model, e.g. "Kanvas\\Inventory\\Products\\Models\\Products"}
                            {--app= : Limit to one app id (default: every app indexing this model into Algolia)}
                            {--dry-run : Report what would be applied without touching the index}
                            {--force : Overwrite settings the index already has}';

    protected $description = 'Push the index settings a model declares to every Algolia index missing them';

    public function handle(): int
    {
        $modelClass = (string) $this->argument('model');

        if (! class_exists($modelClass) || ! method_exists($modelClass, 'algoliaIndexSettings')) {
            $this->error("{$modelClass} is not a model with algoliaIndexSettings().");

            return self::FAILURE;
        }

        $apps = $this->option('app') !== null
            ? [Apps::getById((int) $this->option('app'))]
            : Apps::all();

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $applied = 0;
        $seen = [];

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            $model = new $modelClass();
            $model->setRelation('app', $app);

            if (! $model->isAlgolia()) {
                continue;
            }

            try {
                $reconciler = AlgoliaSettingsReconciler::forApp($app);
                $target = $reconciler->target($model);

                if (isset($seen[$target])) {
                    continue;
                }

                $seen[$target] = true;
                $applied += $this->syncApp(
                    $app,
                    $model,
                    $reconciler,
                    $dryRun,
                    $force
                );
            } catch (Throwable $e) {
                $this->error("app {$app->getId()}: " . $e->getMessage());
            }
        }

        $this->info($dryRun
            ? "{$applied} setting(s) would be applied."
            : "{$applied} setting(s) applied.");

        return self::SUCCESS;
    }

    private function syncApp(
        Apps $app,
        Model $model,
        AlgoliaSettingsReconciler $reconciler,
        bool $dryRun,
        bool $force
    ): int {
        if ($dryRun) {
            $settings = $reconciler->missing($model, $force);
            $error = null;
        } else {
            ['applied' => $settings, 'error' => $error] = $reconciler->reconcile($model, $force);
        }

        $index = $model->searchableAs();

        foreach ($settings as $key => $value) {
            $this->line(sprintf('app %d / %s: %s = %s', $app->getId(), $index, $key, json_encode($value)));
        }

        if ($error !== null) {
            $this->error("app {$app->getId()} / {$index}: {$error}");
        }

        return count($settings);
    }
}
