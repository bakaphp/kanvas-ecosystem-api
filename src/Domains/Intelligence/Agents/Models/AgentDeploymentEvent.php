<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    protected $table      = 'agent_deployment_events';

    public $timestamps = false;

    protected $fillable = [
        'deployment_id',
        'event_type',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    // ── Event type constants ───────────────────────────────────────────────

    public const GATEWAY_DOWN       = 'gateway_down';
    public const GATEWAY_UP         = 'gateway_up';
    public const HEALTH_FAIL        = 'health_fail';
    public const HEALTH_RECOVER     = 'health_recover';
    public const SESSION_STARTED    = 'session_started';
    public const AGENT_UNREACHABLE  = 'agent_unreachable';

    // ── Relations ─────────────────────────────────────────────────────────

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'deployment_id');
    }

    // ── Factory helper ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(int $deploymentId, string $eventType, array $payload = []): self
    {
        return static::create([
            'deployment_id' => $deploymentId,
            'event_type'    => $eventType,
            'payload'       => $payload ?: null,
            'occurred_at'   => now(),
        ]);
    }
}
