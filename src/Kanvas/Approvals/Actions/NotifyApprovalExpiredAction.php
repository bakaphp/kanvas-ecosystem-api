<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Concerns\OffersNoticeToEntity;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\ApprovalExpiredNotification;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Tells the requester and the approvers asked at the step that ran out of time. Same channel rule as a
 * new request: the entity's direct channel first, email otherwise. Best-effort throughout — the expiry is
 * already recorded.
 */
class NotifyApprovalExpiredAction
{
    use OffersNoticeToEntity;

    public function __construct(
        protected readonly ApprovalRequest $request,
        protected readonly ?Model $entity,
    ) {
    }

    public function execute(): int
    {
        $sent = 0;

        foreach ($this->recipients() as $user) {
            try {
                if (! $this->deliveredByEntity($this->request, $this->entity, $user)) {
                    $user->notify(new ApprovalExpiredNotification($this->request));
                }

                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * @return array<int, Users>
     */
    private function recipients(): array
    {
        $users = [];

        $requester = $this->request->requestedByUser;
        if ($requester instanceof Users) {
            $users[$requester->getId()] = $requester;
        }

        $approvers = $this->request->approvers()
            ->where('step', $this->request->current_step)
            ->with('user')
            ->get();

        foreach ($approvers as $approver) {
            if ($approver->user instanceof Users) {
                $users[$approver->user->getId()] = $approver->user;
            }
        }

        return array_values($users);
    }
}
