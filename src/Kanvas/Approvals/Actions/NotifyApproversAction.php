<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Actions;

use Illuminate\Support\Collection;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalNotifyModeEnum;
use Kanvas\Approvals\Jobs\SendApprovalDigestJob;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Models\ApprovalRequestApprover;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Throwable;

/**
 * Tells the current step's approvers there is something waiting on them.
 *
 * Entirely best-effort: a missing email template or an unreachable mail host must never roll back the
 * approval that triggered it. An un-notified approver is a visible pending request someone can chase;
 * a failed create is lost work.
 *
 * The policy's `notify` mode caps the blast radius — a step resolving a 40-person role would otherwise
 * mail all 40, per request, per day.
 */
class NotifyApproversAction
{
    public function __construct(
        protected readonly ApprovalRequest $request,
    ) {
    }

    public function execute(): int
    {
        $mode = ApprovalNotifyModeEnum::fromSetting($this->request->policy?->notify);

        if ($mode === ApprovalNotifyModeEnum::NONE) {
            return 0;
        }

        $recipients = $this->request->approvers()
            ->where('step', $this->request->current_step)
            ->where('decision', ApprovalDecisionEnum::PENDING)
            ->whereNull('notified_at')
            ->with('user')
            ->get();

        if ($mode === ApprovalNotifyModeEnum::DIGEST) {
            $this->scheduleDigests($recipients);

            return 0;
        }

        if ($mode === ApprovalNotifyModeEnum::FIRST) {
            $recipients = $recipients->take(1);
        }

        $sent = 0;

        foreach ($recipients as $recipient) {
            if ($this->notify($recipient)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function notify(ApprovalRequestApprover $recipient): bool
    {
        $user = $recipient->user;

        if ($user === null) {
            return false;
        }

        try {
            $user->notify(new ApprovalRequestedNotification($this->request));
        } catch (Throwable) {
            return false;
        }

        // Stamped only on success, so a retry can pick up whoever was actually missed.
        $recipient->markNotified();

        return true;
    }

    /**
     * @param Collection<int, ApprovalRequestApprover> $recipients
     */
    private function scheduleDigests(Collection $recipients): void
    {
        foreach ($recipients as $recipient) {
            if ($recipient->user === null) {
                continue;
            }

            try {
                SendApprovalDigestJob::schedule($this->request->app, $this->request->company, $recipient->user);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
