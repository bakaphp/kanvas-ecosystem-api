<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Users\Models\Users;

/**
 * An append-only snapshot of an agent's wording, written before each edit so a bad one is a copy back.
 *
 * Deliberately NOT on the Intelligence `BaseModel`: that base adds `AppsIdTrait` and this model used
 * to add `UuidTrait`, and `agent_versions` has neither column — the mismatch went unnoticed because
 * nothing ever wrote a row. Tenancy comes from the parent agent, and a history row needs no external
 * identity of its own.
 *
 * @property int $id
 * @property int $agent_id
 * @property string $version
 * @property array|null $config
 * @property string|null $changes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property bool $is_active
 * @property bool $is_deleted
 */
class AgentVersion extends Model
{
    protected $connection = 'intelligence';

    protected $table = 'agent_versions';

    public $timestamps = false;

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected $fillable = [
        'agent_id',
        'version',
        'config',
        'changes',
        'created_by',
        'created_at',
        'is_active',
    ];

    protected $casts = [
        'config' => Json::class,
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'created_by');
    }
}
