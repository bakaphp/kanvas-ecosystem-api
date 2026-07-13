<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Claims\Models;

use Baka\Traits\KanvasModelTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * A live, exclusive lock an agent holds on an entity while it acts on it.
 *
 * Not soft-deletable: release hard-deletes the row so the unique index
 * (apps_id, companies_id, entity_namespace, entity_id) frees the slot for
 * re-acquire. The audit trail lives in the ledger (claim.acquired /
 * claim.released), so this table only ever holds currently-held claims —
 * which is also what powers "🔒 agent is working on X" in the UI.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property string $entity_namespace
 * @property int $entity_id
 * @property int $agent_id
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class EntityClaim extends Model
{
    use KanvasModelTrait;
    use UuidTrait;

    protected $connection = 'intelligence';
    protected $table = 'nervous_system_entity_claims';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'entity_id' => 'integer',
            'agent_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
