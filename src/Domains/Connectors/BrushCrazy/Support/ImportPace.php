<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * What keeps a full pass from loading the databases more than it has to.
 */
final class ImportPace
{
    public const string PAUSE_CONFIG_KEY = 'brushcrazy.import_pause_ms';

    /**
     * Set by the import command (`--pause-ms`) to spread a full pass over time; 0 for the cron.
     */
    public static function pauseBetweenChunks(): void
    {
        $milliseconds = (int) config(self::PAUSE_CONFIG_KEY, 0);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * The syncs write with model events off, so Scout never heard of these saves. Only the models a
     * sync inserted or changed are sent (plus `$alsoChanged`, changed through a related row): a full
     * pass re-reads every row, and re-indexing an unchanged one costs a queued job that reloads it
     * from MySQL.
     *
     * @param  array<int, Model>  $models
     * @param  array<int, Model>  $alsoChanged
     */
    public static function indexChanged(array $models, array $alsoChanged = []): void
    {
        $changed = [];

        foreach ([...$models, ...$alsoChanged] as $model) {
            if ($model->wasRecentlyCreated || $model->wasChanged() || in_array($model, $alsoChanged, true)) {
                $changed[$model->getKey()] = $model;
            }
        }

        if ($changed !== []) {
            new EloquentCollection(array_values($changed))->searchable();
        }
    }
}
