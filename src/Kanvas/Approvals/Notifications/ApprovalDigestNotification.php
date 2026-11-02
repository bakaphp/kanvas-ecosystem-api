<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Notifications;

use Illuminate\Support\Collection;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * One email for every request a `notify: digest` policy opened for this approver within the window.
 * Template-free, so a tenant switching a policy to digest needs no email template first.
 */
class ApprovalDigestNotification extends Notification
{
    /**
     * @param Collection<int, ApprovalRequest> $requests
     */
    public function __construct(
        Companies $company,
        protected Collection $requests,
    ) {
        /** @var ApprovalRequest $first */
        $first = $requests->first();

        parent::__construct($first, [
            'app' => $first->app,
            'company' => $company,
            'subject' => sprintf('%d approvals waiting for you', $requests->count()),
        ]);

        $this->setType('blank');
        $this->channels = ['mail'];
    }

    #[Override]
    public function getEmailContent(): string
    {
        $items = $this->requests
            ->map(function (ApprovalRequest $request): string {
                $summary = $request->entitySummary();
                $label = $summary === null
                    ? '#' . $request->entity_id
                    : $summary['type'] . ': ' . $summary['label'];

                return '<li>' . e($label) . ' <small>(' . e($request->approval_type) . ')</small></li>';
            })
            ->implode('');

        return '<h2>' . e((string) $this->subject) . '</h2>'
            . '<p>These requests are waiting on your decision:</p>'
            . '<ul>' . $items . '</ul>';
    }
}
