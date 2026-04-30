<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Models;

use Baka\Traits\KanvasModelTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model for the NervousSystem domain.
 *
 * Intentionally lean compared to Intelligence's BaseModel:
 * - no SoftDeletesTrait (ledger and archive rows are append-only / immutable)
 * - no HasCustomFields, no HasFilesystemTrait (event payloads live in JSON columns)
 * - no created_at/updated_at — every model in this domain tracks its own
 *   lifecycle timestamps (occurred_at, indexed_at, archived_at, fired_at, etc.)
 *
 * Provides via KanvasModelTrait:
 * - `intelligence` connection (set explicitly below)
 * - apps_id / companies_id query scopes (fromApp, fromCompany) via KanvasScopesTrait
 * - company() / user() / app() BelongsTo relations
 *
 * Caveat: KanvasModelTrait's static lookup helpers (getById, getByIdFromCompanyApp,
 * etc.) call `notDeleted()` internally, which expects an `is_deleted` column.
 * NervousSystem tables don't have that column, so those helpers will error
 * if called. Use Event::query()->fromApp($app)->where(...) for direct lookups.
 */
class BaseModel extends Model
{
    use KanvasModelTrait;

    protected $connection = 'intelligence';

    public $timestamps = false;
}
