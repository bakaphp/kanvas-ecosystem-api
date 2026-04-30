<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Append-only ledger event. Intentionally does not extend the Intelligence
 * BaseModel — this table has no soft delete, no is_deleted, no
 * created_at/updated_at; rows are immutable once written and pruned via
 * archival rather than deletion.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $source_domain
 * @property string|null $source_entity_type
 * @property int|null $source_entity_id
 * @property string $event_type
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $status
 * @property array|null $payload
 * @property array|null $result
 * @property array|null $error
 * @property int|null $duration_ms
 * @property string|null $correlation_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon $indexed_at
 * @property bool $is_archived
 */
class Event extends Model
{
    use UuidTrait;

    protected $connection = 'intelligence';

    protected $table = 'nervous_system_events';

    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'source_entity_id' => 'integer',
            'actor_id' => 'integer',
            'duration_ms' => 'integer',
            'payload' => 'array',
            'result' => 'array',
            'error' => 'array',
            'occurred_at' => 'datetime',
            'indexed_at' => 'datetime',
            'is_archived' => 'boolean',
        ];
    }

    public function scopeFromApp(Builder $query, int $appsId): Builder
    {
        return $query->where('apps_id', $appsId);
    }

    public function scopeFromCompany(Builder $query, int $companiesId): Builder
    {
        return $query->where('companies_id', $companiesId);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', 0);
    }
}
