<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Models\BaseModel;
use Override;

/**
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
 * @property int $payload_schema_version
 * @property array|null $result
 * @property array|null $error
 * @property int|null $duration_ms
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property Carbon $occurred_at
 * @property Carbon $indexed_at
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
            'payload_schema_version' => 'integer',
            'result' => Json::class,
            'error' => Json::class,
            'occurred_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at');
    }

    public function scopeWithCorrelation(Builder $query, string $correlationId): Builder
    {
        return $query->where('correlation_id', $correlationId);
    }

    public function scopeCausedBy(Builder $query, string $causationId): Builder
    {
        return $query->where('causation_id', $causationId);
    }
}
