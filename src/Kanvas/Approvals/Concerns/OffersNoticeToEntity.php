<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Contracts\DeliversApprovalNoticesInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The one channel rule for every approval notice: the entity's direct channel first, email when it did
 * not reach the person. A direct channel that throws counts as not reached, so the email still goes.
 */
trait OffersNoticeToEntity
{
    protected function deliveredByEntity(ApprovalRequest $request, ?Model $entity, Users $recipient): bool
    {
        if (! $entity instanceof DeliversApprovalNoticesInterface) {
            return false;
        }

        try {
            return $entity->deliverApprovalNotice($request, $recipient);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
