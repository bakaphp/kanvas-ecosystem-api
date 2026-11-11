<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications;

use Illuminate\Support\Collection;
use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\Concerns\RendersApprovalEmail;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * One email for every request a `notify: digest` policy opened for this approver within the window.
 */
class ApprovalDigestNotification extends Notification
{
    use RendersApprovalEmail;

    /**
     * @param Collection<int, ApprovalRequest> $requests
     */
    public function __construct(Companies $company, Collection $requests)
    {
        /** @var ApprovalRequest $first */
        $first = $requests->first();
        $subject = sprintf('%d approvals waiting for you', $requests->count());

        parent::__construct($first, [
            'app' => $first->app,
            'company' => $company,
            'subject' => $subject,
        ]);

        $this->setType('blank');
        $this->setApprovalData(
            $requests->map(fn (ApprovalRequest $request): array => $request->emailSummary())->values()->all(),
            $subject
        );
        $this->channels = ['mail'];
    }

    #[Override]
    public function getEmailContent(): string
    {
        return $this->renderApprovalEmail(ApprovalEmailTemplateEnum::DIGEST);
    }
}
