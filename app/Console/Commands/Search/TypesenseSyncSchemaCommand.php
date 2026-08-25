<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use Baka\Search\TypesenseSchemaReconciler;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Throwable;

class TypesenseSyncSchemaCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:search:typesense-sync-schema
                            {model : FQCN of the searchable model, e.g. "Kanvas\\Souk\\Orders\\Models\\Order"}
                            {--app= : Limit to one app id (default: every app indexing this model into Typesense)}
                            {--dry-run : Report the drift without altering anything}
                            {--all : Also re-type non-widening drift, which reindexes the field lossily}';

    protected $description = 'Re-type Typesense fields whose live collection drifted from the type the model declares';

    public function handle(): int
    {
        $modelClass = (string) $this->argument('model');

        if (! class_exists($modelClass) || ! method_exists($modelClass, 'typesenseCollectionSchema')) {
            $this->error("{$modelClass} is not a model with a typesenseCollectionSchema().");

            return self::FAILURE;
        }

        /** @var iterable<Apps> $apps */
        $apps = $this->option('app') !== null
            ? [Apps::getById((int) $this->option('app'))]
            : Apps::all();

        $dryRun = (bool) $this->option('dry-run');
        $altered = 0;
        $seen = [];

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            /** @var Model $model */
            $model = new $modelClass();
            // searchableAs() and the schema builders read per-app settings off the model's own app
            // relation; without it Products fatals on `->get()` on null.
            $model->setRelation('app', $app);

            if (! $this->indexesIntoTypesense($app, $model)) {
                continue;
            }

            try {
                $reconciler = TypesenseSchemaReconciler::forApp($app);
                $target = $reconciler->target($model);

                if (isset($seen[$target])) {
                    continue;
                }

                $seen[$target] = true;
                $altered += $this->syncApp($app, $model, $reconciler);
            } catch (Throwable $e) {
                $this->error("app {$app->getId()}: " . $e->getMessage());
            }
        }

        $this->info($dryRun
            ? "{$altered} field(s) would be re-typed."
            : "{$altered} field(s) re-typed.");

        return self::SUCCESS;
    }

    private function syncApp(Apps $app, Model $model, TypesenseSchemaReconciler $reconciler): int
    {
        $wideningOnly = ! $this->option('all');

        if ((bool) $this->option('dry-run')) {
            $drift = $reconciler->drift($model, $wideningOnly);
            $failed = [];
        } else {
            ['altered' => $drift, 'failed' => $failed] = $reconciler->reconcile($model, $wideningOnly);
        }

        $collection = $model->searchableAs();

        foreach ($drift as $field) {
            $this->line($this->describe($app, $collection, $field));
        }

        foreach ($failed as $field) {
            $this->error($this->describe($app, $collection, $field) . ' — ' . (string) $field['error']);
        }

        return count($drift);
    }

    private function describe(Apps $app, string $collection, array $field): string
    {
        return sprintf(
            'app %d / %s: %s %s -> %s',
            $app->getId(),
            $collection,
            $field['name'],
            $field['from'],
            $field['to'],
        );
    }

    private function indexesIntoTypesense(Apps $app, Model $model): bool
    {
        $engine = $app->get($model->getTable() . '_search_engine')
            ?? $app->get('search_engine')
            ?? config('scout.driver');

        return $engine === 'typesense';
    }
}
