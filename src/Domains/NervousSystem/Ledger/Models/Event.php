<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
 * Append-only ledger event. Inherits from NervousSystem BaseModel
 * which sets the intelligence connection and provides fromApp /
 * fromCompany scopes. Rows are immutable once written and pruned
 * via archival rather than deletion.
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
class Event extends BaseModel
{
    use UuidTrait;

    protected $table = 'nervous_system_events';

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
            'payload' => Json::class,
            'result' => Json::class,
            'error' => Json::class,
            'occurred_at' => 'datetime',
            'indexed_at' => 'datetime',
            'is_archived' => 'boolean',
        ];
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', 0);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at');
    }
}
