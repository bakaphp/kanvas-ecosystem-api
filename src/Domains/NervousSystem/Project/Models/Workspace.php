<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Upper-level grouping of projects, with an optional portfolio oversight agent. A lean container —
 * the richness lives at the Project level; the workspace just groups and (later) oversees.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int|null $agent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $status
 * @property array|null $config
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Workspace extends BaseModel
{
    use CascadeSoftDeletes;
    use SlugTrait;
    use UuidTrait;

    protected $table = 'nervous_system_workspaces';

    protected $cascadeDeletes = ['projects'];

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'users_id' => 'integer',
            'agent_id' => 'integer',
            'config' => Json::class,
            'metadata' => Json::class,
            'is_deleted' => 'boolean',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(
            Project::class,
            'workspace_id',
            'id'
        )->where('is_deleted', 0);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function oversightAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}
