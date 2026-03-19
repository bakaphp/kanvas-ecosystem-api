<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SoftDeletesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $agent_id
 * @property int $companies_id
 * @property int $apps_id
 * @property int|null $company_task_engagement_item_id
 * @property int|null $message_id
 * @property string|null $entity_namespace
 * @property int|null $entity_id
 * @property string|null $context
 * @property array|null $config
 * @property array|null $external_reference
 * @property array|null $input
 * @property array|null $output
 * @property array|null $error
 * @property bool $is_deleted
 */
class AgentHistory extends BaseModel
{
    use UuidTrait;
    use SoftDeletesTrait;

    protected $fillable = [
        'uuid',
        'agent_id',
        'companies_id',
        'apps_id',
        'company_task_engagement_item_id',
        'message_id',
        'entity_namespace',
        'entity_id',
        'context',
        'config',
        'external_reference',
        'input',
        'output',
        'error',
    ];

    protected $casts = [
        'config' => Json::class,
        'external_reference' => Json::class,
        'input' => Json::class,
        'output' => Json::class,
        'error' => Json::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AgentFeedback::class);
    }

    public function performanceMetrics(): HasMany
    {
        return $this->hasMany(AgentPerformanceMetric::class);
    }
}
