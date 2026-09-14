<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * @deprecated Superseded by Kanvas\Approvals\Models\ApprovalRequest.
 *
 * The table stays for the history already in it, and new rows are still written for tenants with no
 * approval policy configured — but a tenant that has one writes only approval_requests. Seeding a
 * policy (kanvas:approvals:seed-scribe-policies) is what moves a tenant across; when every tenant has
 * one, the remaining writers and ResolveApprovalAction / ResolveApproverEmailAction can go.
 *
 * Durable accounting audit row for an approval request.
 *
 * Cut B usage: expense approval (target_type='expense', action_type='approve_expense').
 * Phase 2: agent-write-action gating (target_type='invoice'+action_type='issue_credit_note', etc.).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int|null $requested_by_agent_id
 * @property int|null $requested_by_users_id
 * @property string $action_type
 * @property string $target_type
 * @property int $target_id
 * @property array|null $payload
 * @property ApprovalQueueStatusEnum $status
 * @property int|null $approved_by_users_id
 * @property Carbon|null $approved_at
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property int|null $nervous_system_plan_id
 * @property int|null $nervous_system_task_id
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class ApprovalQueueItem extends BaseModel
{
    use UuidTrait;

    protected $table = 'approval_queue';
    protected $guarded = [];

    protected $casts = [
        'status' => ApprovalQueueStatusEnum::class,
        'is_deleted' => 'boolean',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'payload' => Json::class,
        'metadata' => Json::class,
    ];

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'requested_by_users_id', 'id');
    }

    public function requestedByAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'requested_by_agent_id', 'id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'approved_by_users_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'nervous_system_plan_id', 'id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'nervous_system_task_id', 'id');
    }
}
