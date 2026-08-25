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

            $target = $this->collectionIdentity($app, $model);

            // Apps that resolve to the same collection on the same server would otherwise be
            // reported — and PATCHed — once per app.
            if (isset($seen[$target])) {
                continue;
            }

            $seen[$target] = true;

            try {
                $altered += $this->syncApp($app, $model, $dryRun);
            } catch (Throwable $e) {
                $this->error("app {$app->getId()}: " . $e->getMessage());
            }
        }

        $this->info($dryRun
            ? "{$altered} field(s) would be re-typed."
            : "{$altered} field(s) re-typed.");

        return self::SUCCESS;
    }

    private function syncApp(Apps $app, Model $model, bool $dryRun): int
    {
        $reconciler = TypesenseSchemaReconciler::forApp($app);
        $wideningOnly = ! $this->option('all');

        if ($dryRun) {
            $drift = $reconciler->drift($model);

            if ($wideningOnly) {
                $drift = array_values(array_filter($drift, fn (array $field) => $field['widening']));
            }

            $failed = [];
        } else {
            ['altered' => $drift, 'failed' => $failed] = $reconciler->reconcile($model, $wideningOnly);
        }

        foreach ($drift as $field) {
            $this->line($this->describe($app, $model, $field));
        }

        foreach ($failed as $field) {
            $this->error($this->describe($app, $model, $field) . ' — ' . $field['error']);
        }

        return count($drift);
    }

    private function describe(Apps $app, Model $model, array $field): string
    {
        return sprintf(
            'app %d / %s: %s %s -> %s',
            $app->getId(),
            $model->searchableAs(),
            $field['name'],
            $field['from'],
            $field['to'],
        );
    }

    /**
     * Which collection, on which server — the two apps-level settings SearchEngineResolver actually
     * builds the client from.
     */
    private function collectionIdentity(Apps $app, Model $model): string
    {
        $settings = $app->get('typesense_search_settings') ?? [];

        return (string) json_encode([
            $model->searchableAs(),
            $settings['typesense_api_key'] ?? config('scout.typesense.api_key'),
            $settings['typesense_nodes'] ?? config('scout.typesense.nodes'),
        ]);
    }

    private function indexesIntoTypesense(Apps $app, Model $model): bool
    {
        $engine = $app->get($model->getTable() . '_search_engine')
            ?? $app->get('search_engine')
            ?? config('scout.driver');

        return $engine === 'typesense';
    }
}
