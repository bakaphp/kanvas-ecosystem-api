<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Services\DailyReportService;

class KanvasDailyReportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:daily-report 
                            {app_id? : The application ID (required for email)}
                            {--date= : Specific date to report on (Y-m-d format, defaults to today)}
                            {--no-cleanup : Skip cleanup of previous day metrics}
                            {--cleanup-date= : Specific date to cleanup (Y-m-d format, defaults to yesterday)}
                            {--email=* : Email addresses to send the report to (can be used multiple times)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and display the Kanvas daily metrics report';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = (string) ($this->option('date') ?? Carbon::now()->format('Y-m-d'));
        $emails = (array) $this->option('email');

        $this->info('');
        $this->line(DailyReportService::formatReport($date));
        $this->info('');

        // Send email if addresses provided
        if (count($emails) > 0) {
            $appId = $this->argument('app_id');

            if ($appId === null) {
                $this->error('❌ app_id is required when sending emails');

                return self::FAILURE;
            }

            /** @var Apps $app */
            $app = Apps::getById((int) $appId);
            $this->overwriteAppService($app);

            $this->sendReportEmails($app, $emails, $date);
        }

        // Cleanup previous day by default (skip with --no-cleanup)
        if (! $this->option('no-cleanup')) {
            $cleanupDate = (string) ($this->option('cleanup-date') ?? Carbon::yesterday()->format('Y-m-d'));
            $cleaned = DailyReportService::cleanup($cleanupDate);

            $this->info("🧹 Cleaned up {$cleaned} metric keys from {$cleanupDate}");
        }

        return self::SUCCESS;
    }

    /**
     * Send the report to multiple email addresses
     */
    private function sendReportEmails(Apps $app, array $emails, string $date): void
    {
        $metrics = DailyReportService::getMetricsWithTotals($date);

        $data = [
            'app' => $app,
            'date' => $date,
            'metrics' => $metrics,
            'report' => DailyReportService::formatReport($date),
            'subject' => "📊 Kanvas Daily Report - {$date}",
        ];

        foreach ($emails as $email) {
            $notification = new Blank(
                'kanvas-daily-report',
                $data,
                ['mail'],
                $app
            );
            $notification->setSubject($data['subject']);

            Notification::route('mail', $email)->notify($notification);
            $this->info("📧 Report sent to {$email}");
        }
    }
}
