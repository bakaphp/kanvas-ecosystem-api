<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications;

use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Notifications\Notification;

/**
 * Tells one approver a decision is waiting on them.
 *
 * Sent per step activation and only to that step's approvers — someone queued behind an unfinished
 * step has nothing to act on yet, and asking them early is how an approval chain stops meaning
 * anything.
 */
class ApprovalRequestedNotification extends Notification
{
    public function __construct(ApprovalRequest $request)
    {
        parent::__construct($request, [
            'app' => $request->app,
            'company' => $request->company,
            'subject' => 'Approval needed: ' . $request->approval_type,
        ]);

        $this->channels = ['mail'];
    }
}
