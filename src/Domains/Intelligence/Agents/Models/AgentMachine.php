<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $slug
 * @property string $host
 * @property int $ssh_port
 * @property string $ssh_user
 * @property string $ssh_private_key
 * @property string|null $region
 * @property int $port_range_start
 * @property int $port_range_end
 * @property int $max_agents
 * @property bool $is_active
 * @property bool $is_deleted
 */
class AgentMachine extends BaseModel
{
    use DatabaseSearchableTrait;
    use UuidTrait;
    use SlugTrait;

    protected $table = 'agent_machines';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'name',
        'slug',
        'host',
        'ssh_port',
        'ssh_user',
        'ssh_private_key',
        'region',
        'port_range_start',
        'port_range_end',
        'max_agents',
        'is_active',
    ];

    protected $hidden = [
        'ssh_private_key',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(AgentDeployment::class, 'agent_machine_id');
    }

    public function activeDeployments(): HasMany
    {
        return $this->hasMany(AgentDeployment::class, 'agent_machine_id')
            ->where('status', 'running')
            ->where('is_deleted', 0);
    }

    public function hasCapacity(): bool
    {
        return $this->activeDeployments()->count() < $this->max_agents;
    }

    /**
     * Find the next available port pair (gateway + proxy) in this machine's range.
     *
     * @return array{gateway_port: int, proxy_port: int}
     */
    public function allocatePortPair(): array
    {
        $usedPorts = AgentDeployment::where('agent_machine_id', $this->id)
            ->whereNotIn('status', ['terminated'])
            ->where('is_deleted', 0)
            ->pluck('gateway_port')
            ->merge(
                AgentDeployment::where('agent_machine_id', $this->id)
                    ->whereNotIn('status', ['terminated'])
                    ->where('is_deleted', 0)
                    ->pluck('proxy_port')
            )
            ->toArray();

        for ($port = $this->port_range_start; $port < $this->port_range_end; $port += 2) {
            $proxyPort = $port + 1;

            if (! in_array($port, $usedPorts) && ! in_array($proxyPort, $usedPorts)) {
                return [
                    'gateway_port' => $port,
                    'proxy_port' => $proxyPort,
                ];
            }
        }

        throw new ValidationException('No available ports on machine: ' . $this->name);
    }

    public function connectSsh(): SshClient
    {
        return SshClient::fromMachine($this);
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . 'agent_machines_index';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'region' => $this->region,
        ];
    }
}
