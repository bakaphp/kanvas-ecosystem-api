<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use Kanvas\Intelligence\Models\ImmutableBaseModel;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Enums\MessageStatus;
use Override;

/**
 * The `agent` string column is overloaded by design:
 *  - KanvasConversationStore writes the PHP class name of the originating
 *    Laravel\Ai\Agent.
 *  - Hermes ingestion writes the Kanvas Agent's UUID string instead, so
 *    "every message produced by agent X" is a single indexed string match.
 *
 * `steps` is the Laravel AI 1.x shape of an assistant turn: one entry per model round trip, each
 * carrying `content`, `tool_calls` (every call with its own `result`, or an `approval_reason` while
 * it waits), `reasoning`, `replay_blocks` and `provider_tool_calls`. `tool_calls` / `tool_results`
 * are accessors derived from `steps`, kept for the GraphQL fields of the same name while clients move
 * to `steps` — transitional, removed in the v2 cleanup.
 *
 * `status` is `completed`, `paused` (a tool asked for approval) or `failed` (the run threw; the
 * error is in `meta.error`). Only the laravel path produces the last two.
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
 * @property string|null $participant_type
 * @property int|null $participant_id
 * @property string $agent
 * @property string $role
 * @property bool $is_public
 * @property string|null $content
 * @property array|null $attachments
 * @property array $steps
 * @property string $status
 * @property array|null $usage
 * @property array|null $meta
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read list<array<string, mixed>> $tool_calls
 * @property-read list<array<string, mixed>> $tool_results
 */
class AgentConversationMessage extends ImmutableBaseModel
{
    public const string STATUS_COMPLETED = MessageStatus::Completed->value;
    public const string STATUS_PAUSED = MessageStatus::Paused->value;
    public const string STATUS_FAILED = MessageStatus::Failed->value;

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
            'steps' => Json::class,
            'usage' => Json::class,
            'meta' => Json::class,
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected function toolCalls(): Attribute
    {
        return Attribute::get(fn (): array => ConversationStepsHelper::legacyColumnsFromSteps($this->steps ?? [])[0]);
    }

    protected function toolResults(): Attribute
    {
        return Attribute::get(fn (): array => ConversationStepsHelper::legacyColumnsFromSteps($this->steps ?? [])[1]);
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

    public function participant(): MorphTo
    {
        return $this->morphTo('participant', 'participant_type', 'participant_id');
    }
}
