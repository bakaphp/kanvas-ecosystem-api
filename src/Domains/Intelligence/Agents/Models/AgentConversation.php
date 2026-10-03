<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\ImmutableBaseModel;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Schema gotchas:
 *  - `id` is a string uuid7 (Laravel AI shape), not an autoincrement.
 *  - `participant_type`/`participant_id` say who the conversation belongs to — a Users row for an
 *    end-user chat, a People row for a public-agent chat, an Agent row for an agent-owned one
 *    (runtime import, scheduled wake). NULL on an anonymous public session until it is keyed to a
 *    Person, and on rows written before the column existed until the backfill runs.
 *  - `user_id` is the acting Kanvas user, always set once backfilled: the person, or the agent's
 *    dedicated user when the agent ran the turn. `agent_id` is which agent the conversation is with.
 *  - `meta` is a free-form runtime blob (Hermes stuffs model/system_prompt/source/
 *    parent_session_id/costs/handoff_state + the import watermark in here).
 *  - `user_id` (this table) ≠ `users_id` (KanvasModelTrait's default FK) —
 *    that's why user() is overridden below.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $participant_type
 * @property int|null $participant_id
 * @property int|null $agent_id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $title
 * @property array|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AgentConversation extends ImmutableBaseModel
{
    protected $table = 'agent_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'meta' => Json::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    public function participant(): MorphTo
    {
        return $this->morphTo('participant', 'participant_type', 'participant_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(
            AgentConversationMessage::class,
            'conversation_id',
            'id'
        );
    }

    /**
     * Hide legacy rows that pre-date the agent_id backfill (where the column
     * is NULL). Used by the `agentConversations` GraphQL query so old
     * KanvasConversationStore data doesn't leak across agents.
     */
    public function scopeLinkedToAgent(Builder $query): Builder
    {
        return $query->whereNotNull('agent_id');
    }

    #[Override]
    public function scopeFromUser(Builder $query, mixed $app = null): Builder
    {
        $userId = auth()->id();

        return $userId !== null
            ? $query->where('user_id', $userId)
            : $query;
    }
}
