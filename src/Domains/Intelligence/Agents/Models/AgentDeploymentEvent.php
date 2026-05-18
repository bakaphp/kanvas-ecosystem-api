<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Intelligence\Agents\Enums\AgentDeploymentEventTypeEnum;

/**
 * @property int         $id
 * @property int         $deployment_id
 * @property string      $event_type
 * @property array|null  $payload
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class AgentDeploymentEvent extends Model
{
    protected $connection = 'intelligence';
    protected $table = 'agent_deployment_events';

    public $timestamps = false;

    protected $fillable = [
        'deployment_id',
        'event_type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'deployment_id');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(
        int $deploymentId,
        AgentDeploymentEventTypeEnum|string $eventType,
        array $payload = [],
    ): self {
        return static::create([
            'deployment_id' => $deploymentId,
            'event_type' => $eventType instanceof AgentDeploymentEventTypeEnum
                ? $eventType->value
                : $eventType,
            'payload' => $payload ?: null,
            'occurred_at' => now(),
        ]);
    }
}
