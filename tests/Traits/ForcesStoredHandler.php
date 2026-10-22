<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait ForcesStoredHandler
{
    /**
     * The model refuses a handler that does not implement its contract (Baka\Concerns\HasHandlerClass),
     * so a stale or foreign handler — the case the runtime must survive — is written by hand.
     *
     * @template TModel of Model
     *
     * @param TModel $model
     *
     * @return TModel
     */
    protected function forceStoredHandler(Model $model, string $handler): Model
    {
        DB::connection($model->getConnectionName())
            ->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->update(['handler' => $handler]);

        return $model->refresh();
    }
}
