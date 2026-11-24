<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications;

use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\Concerns\RendersApprovalEmail;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Tells the requester and the approvers who never answered that a request ran out of time, so the record
 * it held does not sit unnoticed.
 */
class ApprovalExpiredNotification extends Notification
{
    use RendersApprovalEmail;

    public function __construct(ApprovalRequest $request)
    {
        $summary = $request->emailSummary();
        $subject = 'Approval expired: ' . $summary['action'] . ' — ' . $summary['name'];

        parent::__construct($request, [
            'app' => $request->app,
            'company' => $request->company,
            'subject' => $subject,
        ]);

        $this->setApprovalData(
            [$summary],
            $subject,
            'Nobody decided this request before it expired. The record was sent back for review; submit it again when it is ready.'
        );
        $this->channels = ['mail'];
    }

    #[Override]
    public function getEmailContent(): string
    {
        return $this->renderApprovalEmail(ApprovalEmailTemplateEnum::EXPIRED);
    }
}
