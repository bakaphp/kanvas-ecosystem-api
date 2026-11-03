<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications;

use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\Concerns\RendersApprovalEmail;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Tells one approver a decision is waiting on them.
 *
 * Sent per step activation and only to that step's approvers — someone queued behind an unfinished
 * step has nothing to act on yet, and asking them early is how an approval chain stops meaning
 * anything.
 */
class ApprovalRequestedNotification extends Notification
{
    use RendersApprovalEmail;

    public function __construct(ApprovalRequest $request)
    {
        $summary = $request->emailSummary();
        $subject = 'Approval needed: ' . $summary['action'] . ' — ' . $summary['name'];

        parent::__construct($request, [
            'app' => $request->app,
            'company' => $request->company,
            'subject' => $subject,
        ]);

        $this->setApprovalData([$summary], $subject);
        $this->channels = ['mail'];
    }

    #[Override]
    public function getEmailContent(): string
    {
        return $this->renderApprovalEmail(ApprovalEmailTemplateEnum::REQUESTED);
    }
}
