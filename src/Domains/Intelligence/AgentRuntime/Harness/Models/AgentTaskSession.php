<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Models\BaseModel;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property int|null $agent_id
 * @property int|null $plan_id
 * @property int $task_id
 * @property int|null $agent_machine_id
 * @property string|null $repo_slug
 * @property string $harness
 * @property string|null $external_session_id
 * @property string $status
 * @property string|null $container_name
 * @property int|null $port
 * @property string|null $endpoint
 * @property string|null $server_password
 * @property string|null $workspace_path
 * @property Carbon|null $workspace_reaped_at
 * @property string|null $session_data_path
 * @property string|null $branch
 * @property string|null $pull_request_url
 * @property int|null $continues_session_id
 * @property string|null $last_cursor
 * @property int|null $last_message_at
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $credential_source
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $estimated_cost
 * @property string|null $handoff
 * @property string|null $error_message
 * @property int $agent_steer_count
 * @property Carbon|null $started_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class AgentTaskSession extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_task_sessions';
    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'completed_at' => 'datetime',
            'workspace_reaped_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(AgentMachine::class, 'agent_machine_id', 'id');
    }

    public function harnessName(): HarnessEnum
    {
        return HarnessEnum::from($this->harness);
    }

    public function harnessStatus(): HarnessStatusEnum
    {
        return HarnessStatusEnum::from($this->status);
    }

    public function isLive(): bool
    {
        return ! $this->harnessStatus()->isTerminal();
    }

    public function usage(): HarnessUsage
    {
        return new HarnessUsage(
            inputTokens: $this->input_tokens,
            outputTokens: $this->output_tokens,
            cacheReadTokens: $this->cache_read_tokens,
            cacheWriteTokens: $this->cache_write_tokens,
        );
    }

    public function elapsedSeconds(): int
    {
        $start = $this->started_at ?? $this->created_at;

        if ($start === null) {
            return 0;
        }

        return (int) $start->diffInSeconds($this->completed_at ?? Carbon::now(), absolute: true);
    }

    /**
     * Silence is the only failure signal a harness reliably gives — a wedged provider call produces no
     * error event and no message, so "nothing new for a while" is what the sweeper and the supervisor
     * both key on.
     */
    public function secondsSinceHeartbeat(): ?int
    {
        if ($this->heartbeat_at === null) {
            return null;
        }

        return (int) $this->heartbeat_at->diffInSeconds(Carbon::now(), absolute: true);
    }

    public function touchHeartbeat(): void
    {
        $this->heartbeat_at = Carbon::now();
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('status', HarnessStatusEnum::terminalValues());
    }

    /**
     * One harness's sessions on one machine — the predicate every sweep starts from.
     *
     * A machine can host more than one harness, and the reaper acts on the filesystem, so "whose is
     * this" has to be asked identically everywhere it is asked. Written out per query it was already
     * three copies in two clause orders.
     */
    public function scopeOnMachine(Builder $query, int $machineId, HarnessEnum $harness): Builder
    {
        return $query->where('agent_machine_id', $machineId)->where('harness', $harness->value);
    }

    /**
     * Sessions on a machine whose checkout can be deleted: ended, older than the retention window, and
     * not already reaped. Oldest first, because the caller batches — without an order the choice of
     * which expired rows make the batch is the database's, and one can sit unreaped indefinitely.
     *
     * "Ended" is a terminal STATUS, not `completed_at IS NOT NULL`. A run that failed or was killed
     * frequently never reaches the code that stamps that column, and those are precisely the sessions
     * that leave the largest directories behind.
     */
    public function scopeWorkspaceReapable(
        Builder $query,
        int $machineId,
        int $retentionHours,
        HarnessEnum $harness
    ): Builder {
        $cutoff = Carbon::now()->subHours($retentionHours);

        return $query->onMachine($machineId, $harness)
            ->whereIn('status', HarnessStatusEnum::terminalValues())
            ->whereNotNull('workspace_path')
            ->whereNull('workspace_reaped_at')
            ->where(
                fn (Builder $ended): Builder => $ended
                    ->where('completed_at', '<', $cutoff)
                    ->orWhere(
                        fn (Builder $unstamped): Builder => $unstamped
                            ->whereNull('completed_at')
                            ->where('updated_at', '<', $cutoff)
                    )
            )
            ->orderByRaw('COALESCE(completed_at, updated_at) ASC');
    }

    /**
     * The latest session an agent ran for a job, scoped to that agent's tenant.
     *
     * A job id reaches us from an LLM, so the tenant filter is the thing stopping one company's agent
     * reporting on — or cancelling — another's work. Ten tools needed this and each wrote it out; one
     * of them forgetting a clause is a cross-tenant read that nothing would catch.
     */
    public static function forAgentJob(Agent $agent, int $taskId): ?self
    {
        /** @var self|null $session */
        $session = self::query()
            ->forTask($taskId)
            ->fromApp($agent->app)
            ->fromCompany($agent->company)
            ->latest('id')
            ->first();

        return $session;
    }

    /**
     * The pull request number, from the URL the push recorded.
     *
     * On the model because three tools were each re-deriving it from the same stored string.
     */
    public function pullRequestNumber(): ?int
    {
        return preg_match('#/pull/(\d+)#', (string) $this->pull_request_url, $m) === 1
            ? (int) $m[1]
            : null;
    }

    public function scopeForTask(Builder $query, int $taskId): Builder
    {
        return $query->where('task_id', $taskId);
    }
}
