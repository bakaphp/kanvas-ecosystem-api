<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Models\BaseModel;

/**
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
}
