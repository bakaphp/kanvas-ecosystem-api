<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Intelligence\Models\BaseModel;
use Nevadskiy\Tree\AsTree;

/**
 * @property int $agent_swarm_id
 * @property int $agent_id
 * @property int|null $parent_id
 * @property string|null $path
 * @property string|null $role
 * @property array|null $config
 * @property bool $is_deleted
 */
class AgentSwarmMember extends BaseModel
{
    use AsTree;
    use NoAppRelationshipTrait;

    protected $table = 'agent_swarm_members';

    protected $fillable = [
        'agent_swarm_id',
        'agent_id',
        'parent_id',
        'path',
        'role',
        'config',
        'is_deleted',
    ];

    protected $casts = [
        'config' => Json::class,
    ];

    public function swarm(): BelongsTo
    {
        return $this->belongsTo(AgentSwarm::class, 'agent_swarm_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Override AsTree's circular reference check to handle legacy members
     * that have a NULL path (created via BelongsToMany::attach() which
     * bypasses Eloquent model events, so AsTree never set the path).
     * Without this, the trait calls $this->parent->getPath()->segments()
     * which crashes with "Call to a member function segments() on null".
     */
    protected function hasCircularReference(): bool
    {
        if ($this->isRoot()) {
            return false;
        }

        $parentPath = $this->parent?->getPath();

        if ($parentPath === null) {
            return false;
        }

        return $parentPath->segments()->contains($this->getPathSource());
    }

    /**
     * Override AsTree's subtree path rebuild to backfill NULL paths on
     * legacy members before running the rebuild. The trait's original
     * method passes $this->getPath() and $this->parent->getPath() to
     * rebuildPaths() which requires non-null Path objects. Legacy members
     * created via attach() have path = NULL, causing:
     * "Argument #2 ($path) must be of type Path, null given".
     *
     * This override assigns paths to self and parent if missing, then
     * runs the standard rebuild logic.
     */
    protected function rebuildSubtreePaths(): void
    {
        if (! $this->hasPath()) {
            $this->assignPath();
            $this->saveQuietly();
        }

        if (! $this->isRoot() && $this->parent && ! $this->parent->hasPath()) {
            $this->parent->assignPath();
            $this->parent->saveQuietly();
        }

        $this->newQuery()
            ->whereSelfOrDescendantOf($this)
            ->rebuildPaths(
                $this->getPathColumn(),
                $this->getPath(),
                $this->isRoot()
                    ? null
                    : $this->parent?->getPath(),
            );
    }
}
