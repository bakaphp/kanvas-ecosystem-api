<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasModelTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Workflow\Models\Integrations;
use Override;

/**
 * No `is_deleted` column, so `KanvasModelTrait`'s static lookups (`getById`, …) will error — they call
 * `notDeleted()`. Read with `query()->where(...)`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agents_id
 * @property int $integrations_id
 * @property int|null $users_id
 * @property string $session_uuid
 * @property string $start_tool
 * @property string $external_id
 * @property string $status
 * @property string|null $external_status
 * @property string|null $live_url
 * @property string|null $live_url_posted
 * @property string|null $result
 * @property array|null $artifacts
 * @property string|null $last_error
 * @property int $poll_count
 * @property int $consecutive_errors
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 */
class McpAsyncJob extends Model
{
    use EmitsLedgerEventsForEntity;
    use KanvasModelTrait;
    use UuidTrait;

    public const int MAX_RESULT_CHARS = 30000;

    /** Str::limit's default "..." reads as the vendor's own ellipsis; the agent has to know it was cut. */
    public const string TRUNCATION_MARKER = ' […output truncated by Kanvas: ask for the file instead…]';

    protected $connection = 'intelligence';

    protected $table = 'nervous_system_mcp_async_jobs';

    public $timestamps = true;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'agents_id' => 'integer',
            'integrations_id' => 'integer',
            'users_id' => 'integer',
            'poll_count' => 'integer',
            'consecutive_errors' => 'integer',
            'artifacts' => Json::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agents_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integrations_id');
    }

    /** A channel session uuid is shared by every agent on it, so resolve through the agent, newest first. */
    public function resolveSession(): ?Session
    {
        if ($this->agent === null) {
            return null;
        }

        /** @var Session|null $session */
        $session = Session::query()
            ->fromAgent($this->agent)
            ->where('uuid', $this->session_uuid)
            ->first();

        return $session;
    }

    public function isRunning(): bool
    {
        return $this->status === McpAsyncJobStatusEnum::RUNNING->value;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasUnpostedLiveUrl(): bool
    {
        return $this->live_url !== null && $this->live_url !== $this->live_url_posted;
    }

    public function finish(McpAsyncJobStatusEnum $status, ?string $result = null, ?string $error = null): void
    {
        $this->status = $status->value;
        $this->result = $result !== null
            ? Str::limit($result, self::MAX_RESULT_CHARS, self::TRUNCATION_MARKER)
            : $this->result;
        $this->last_error = $error !== null ? Str::limit($error, 2000) : $this->last_error;
        $this->completed_at = Carbon::now();
        $this->saveOrFail();
    }

    /** Without "do not start the job again" a model re-runs the task it was waiting on. */
    public function resumeInstruction(): string
    {
        $header = sprintf(
            '[Background job update] The `%s` job %s you started earlier in this conversation',
            $this->start_tool,
            $this->external_id
        );

        return match (McpAsyncJobStatusEnum::from($this->status)) {
            McpAsyncJobStatusEnum::COMPLETED => $header . sprintf(
                " finished (status: %s). Its result:\n\n%s\n%s\nContinue the user's request with this result "
                . 'and tell them what came of it. Do not start the job again.',
                $this->external_status ?? 'done',
                $this->result ?? '(the job returned no output)',
                $this->artifactsNote()
            ),
            McpAsyncJobStatusEnum::TIMED_OUT => $header . ' was still running when Kanvas stopped waiting for it. '
                . 'Tell the user it did not finish in time and that they can check the live view or ask you '
                . 'to look at it again.',
            McpAsyncJobStatusEnum::FAILED => $header . ' could not be followed to the end: '
                . ($this->last_error ?? 'the status check kept failing') . '. Tell the user it failed and why.',
            McpAsyncJobStatusEnum::RUNNING => $header . ' is still running.',
        };
    }

    /** A day's export would bury the turn, so the agent gets the plan, never the contents. */
    private function artifactsNote(): string
    {
        $planId = $this->artifacts['plan_id'] ?? null;
        $files = (array) ($this->artifacts['files'] ?? []);

        return $planId === null || $files === []
            ? ''
            : sprintf(
                "\nIt also produced %d file(s), saved to plan #%d: %s. The files are on that plan — do not ask "
                . "the vendor for them again.\n",
                count($files),
                (int) $planId,
                implode(', ', array_map('strval', $files))
            );
    }

    protected function resolveDefaultActorType(): string
    {
        return 'Agent';
    }

    protected function resolveDefaultActorId(): ?int
    {
        return $this->agents_id;
    }
}
