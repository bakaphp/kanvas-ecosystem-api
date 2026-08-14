<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Models\ImmutableBaseModel;
use Kanvas\Users\Models\Users;
use Override;

/**
 * The `agent` string column is overloaded by design:
 *  - KanvasConversationStore writes the PHP class name of the originating
 *    Laravel\Ai\Agent.
 *  - Hermes ingestion writes the Kanvas Agent's UUID string instead, so
 *    "every message produced by agent X" is a single indexed string match.
 *
 * `meta` is the runtime-specific blob — Hermes stuffs runtime_message_id,
 * tool_call_id, tool_name, finish_reason, token_count, reasoning_*,
 * codex_*, occurred_at, runtime_source_reader into it.
 *
 * `user_id` (this table) ≠ `users_id` (KanvasModelTrait's default FK) —
 * see the user() override below.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int|null $user_id
 * @property string $agent
 * @property string $role
 * @property bool $is_public
 * @property string|null $content
 * @property array|null $attachments
 * @property array|null $tool_calls
 * @property array|null $tool_results
 * @property array|null $usage
 * @property array|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AgentConversationMessage extends ImmutableBaseModel
{
    protected $table = 'agent_conversation_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'attachments' => Json::class,
            'tool_calls' => Json::class,
            'tool_results' => Json::class,
            'usage' => Json::class,
            'meta' => Json::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(
            AgentConversation::class,
            'conversation_id',
            'id'
        );
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
