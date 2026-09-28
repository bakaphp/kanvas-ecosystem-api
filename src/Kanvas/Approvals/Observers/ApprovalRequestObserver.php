<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Observers;

use Kanvas\Approvals\Models\ApprovalRequest;

class ApprovalRequestObserver
{
    /**
     * Approver rows are owned entirely by their request and mean nothing without it, so retiring the
     * request retires them.
     *
     * Hooked on `softDeleted`, not `deleted`: Kanvas soft-delete is an is_deleted flag fired through
     * KanvasModelTrait::softDelete(), which Laravel's own delete events never see. The model declares
     * it in $observables so this method is registered at all.
     */
    public function softDeleted(ApprovalRequest $request): void
    {
        $request->approvers()->update(['is_deleted' => true]);
    }
}
