<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_id
 * @property int $agent_machine_id
 * @property string $system_user
 * @property string $home_directory
 * @property int $gateway_port
 * @property int $proxy_port
 * @property string $container_name
 * @property string $status
 * @property Carbon|null $launched_at
 * @property Carbon|null $terminated_at
 * @property Carbon|null $last_health_check
 * @property string|null $error_message
 * @property bool $is_deleted
 */
class AgentDeployment extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_deployments';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agent_id',
        'agent_machine_id',
        'system_user',
        'home_directory',
        'gateway_port',
        'proxy_port',
        'container_name',
        'status',
        'launched_at',
        'terminated_at',
        'last_health_check',
        'error_message',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'launched_at' => 'datetime',
            'terminated_at' => 'datetime',
            'last_health_check' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(AgentMachine::class, 'agent_machine_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
