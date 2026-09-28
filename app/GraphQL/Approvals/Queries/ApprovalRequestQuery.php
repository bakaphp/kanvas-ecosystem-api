<?php

declare(strict_types=1);

namespace App\GraphQL\Approvals\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;

class ApprovalRequestQuery
{
    use ResolvesActingContext;

    /**
     * Only what is actually on this user's desk: an approver row of theirs that is PENDING *and* sits
     * at the request's live step. Matching on the row alone would also surface requests where they are
     * queued behind a step that has not cleared yet.
     */
    public function myPendingApprovals(mixed $rootValue, array $args): Builder
    {
        $ctx = $this->actingContext();

        return ApprovalRequest::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->notDeleted()
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->whereHas(
                'approvers',
                fn (Builder $query) => $query
                    ->where('users_id', $ctx->user->getId())
                    ->where('decision', ApprovalDecisionEnum::PENDING->value)
                    ->whereColumn('approval_request_approvers.step', 'approval_requests.current_step')
            )
            ->orderByDesc('id');
    }
}
