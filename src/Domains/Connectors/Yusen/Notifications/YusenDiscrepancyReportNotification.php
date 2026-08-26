<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Notifications;

use Illuminate\Support\Facades\View;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail-only discrepancy report for one Yusen Item Balance delivery.
 *
 * The body is a Blade file in the repo rather than a row in `email_templates`, matching
 * `DailyLearningDigestNotification`. A DB template would mean the connector silently sends nothing
 * until somebody remembers to create the row, and the markup would live outside code review.
 */
class YusenDiscrepancyReportNotification extends Notification
{
    private const string VIEW = 'emails.connectors.yusen.discrepancy-report';

    public array $channels = ['mail'];

    /**
     * @param array<string, mixed> $report the pivoted report built by SendYusenDiscrepancyReportAction
     */
    public function __construct(
        Companies $company,
        array $report,
    ) {
        parent::__construct($company, [
            'company' => $company,
        ]);

        $this->setSubject($report['subject']);
        $this->setData($report);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return View::make(self::VIEW, $this->getData())->render();
    }
}
