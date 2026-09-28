<?php

declare(strict_types=1);

namespace App\GraphQL\Approvals\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Actions\DelegateApprovalAction;
use Kanvas\Approvals\Actions\RejectAction;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;

/**
 * A thin shell over the actions — it has no privileges they don't. Whether the caller may approve is
 * decided by the approver rows at the current step inside ApproveAction, which is why these are
 * @guard rather than @can: someone with `edit` on the underlying record must not thereby be able to
 * approve it.
 */
class ApprovalRequestMutation
{
    use ResolvesActingContext;

    public function approve(mixed $rootValue, array $request): ApprovalRequest
    {
        $ctx = $this->actingContext();

        return new ApproveAction(
            $this->requestFromInput($request['input']),
            $ctx->user,
            $request['input']['reason'] ?? null,
        )->execute()->request;
    }

    public function reject(mixed $rootValue, array $request): ApprovalRequest
    {
        $ctx = $this->actingContext();
        $reason = trim((string) ($request['input']['reason'] ?? ''));

        if ($reason === '') {
            throw new ValidationException('A rejection must say why.');
        }

        return new RejectAction(
            $this->requestFromInput($request['input']),
            $ctx->user,
            $reason
        )->execute()->request;
    }

    public function delegate(mixed $rootValue, array $request): ApprovalRequest
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Users $delegate */
        $delegate = Users::getById((int) $input['users_id']);

        return new DelegateApprovalAction(
            $this->requestFromInput($input),
            $ctx->user,
            $delegate,
            $input['reason'] ?? null,
        )->execute()->request;
    }

    /**
     * Cancelling withdraws a request nobody decided on, so it is restricted to whoever opened it (or
     * an admin) rather than to its approvers — an approver's move is reject, not cancel.
     */
    public function cancel(mixed $rootValue, array $request): ApprovalRequest
    {
        $ctx = $this->actingContext();
        $approvalRequest = $this->requestFromInput($request['input']);

        $isRequester = $approvalRequest->requested_by_users_id === $ctx->user->getId();

        if (! $isRequester && ! $ctx->user->isAdmin()) {
            throw new ValidationException('Only the requester or an admin can cancel an approval.');
        }

        return new CancelApprovalAction($approvalRequest, $request['input']['reason'] ?? null)
            ->execute()->request;
    }

    private function requestFromInput(array $input): ApprovalRequest
    {
        $ctx = $this->actingContext();

        /** @var ApprovalRequest $request */
        $request = ApprovalRequest::getByIdFromCompanyApp((int) $input['id'], $ctx->company, $ctx->app);

        return $request;
    }
}
