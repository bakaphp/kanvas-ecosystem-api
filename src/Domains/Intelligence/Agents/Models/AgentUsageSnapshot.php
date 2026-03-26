<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $agent_deployment_id
 * @property Carbon|null $snapshot_date
 * @property string|null $source
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $total_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string|null $provider
 * @property string|null $model
 * @property int $total_sessions
 * @property string|null $raw_output
 * @property array|null $parsed_data
 * @property bool $is_deleted
 */
class AgentUsageSnapshot extends BaseModel
{
    use DatabaseSearchableTrait;
    use UuidTrait;
    use SoftDeletesTrait;

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agent_deployment_id',
        'snapshot_date',
        'source',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'provider',
        'model',
        'total_sessions',
        'raw_output',
        'parsed_data',
    ];

    protected $casts = [
        'parsed_data' => Json::class,
        'snapshot_date' => 'date',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'total_sessions' => 'integer',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'agent_deployment_id');
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . 'agent_usage_snapshots_index';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}
