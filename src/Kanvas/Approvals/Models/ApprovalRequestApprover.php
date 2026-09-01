<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * @property int $id
 * @property int $approval_requests_id
 * @property int $users_id
 * @property string|null $email
 * @property int $step
 * @property ApprovalDecisionEnum $decision
 * @property bool $is_deleted
 */
class ApprovalRequestApprover extends BaseModel
{
    protected $table = 'approval_request_approvers';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'decision' => ApprovalDecisionEnum::class,
        'decided_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function decisionName(): string
    {
        return $this->decision->value;
    }

    public function delegatedTo(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'delegated_to_users_id', 'id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_requests_id', 'id');
    }
}
