<?php

declare(strict_types=1);

namespace Kanvas\Reporting\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\CreateTemplate;
use Kanvas\Reporting\Notifications\DailyAnalyticsReportNotification;

class SendAnalyticsEmailAction
{
    public function __construct(
        protected Apps $app,
        protected array $reportData,
        protected string $date
    ) {}

    public function execute(): void
    {
        $recipients = [
            'cian@promptmine.ai',
            'edikan@promptmine.ai'
        ];

        foreach ($recipients as $email) {
            // Send Notification using Kanvas Notification System
            // We'll create a new DailyAnalyticsReportNotification
            (new DailyAnalyticsReportNotification($this->reportData, $this->date))
                ->to($email)
                ->via(['mail'])
                ->send();
        }
    }
}
