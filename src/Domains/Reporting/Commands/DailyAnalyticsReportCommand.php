<?php

declare(strict_types=1);

namespace Kanvas\Reporting\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Amplitude\Client;
use Kanvas\Reporting\Actions\GenerateDailyAnalyticsReportAction;
use Kanvas\Reporting\Actions\SendAnalyticsEmailAction;

class DailyAnalyticsReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:daily-analytics-report {app_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send daily EOD analytics report from Amplitude data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $appId = $this->argument('app_id') ?? 1; // Default to App ID 1 (PromptMine)
        $app = Apps::getById($appId);

        $this->info("Starting Daily Analytics Report for App: {$app->name}");

        try {
            // 1. Fetch Data from Amplitude (Yesterday)
            $client = new Client($app, $app->company); // Assuming company context is app owner
            $date = date('Y-m-d', strtotime('-1 day'));
            
            $this->info("Fetching Amplitude data for: {$date}");
            $events = $client->eventsExport($date, $date); 

            // 2. Process Data & Generate Report
            $reportData = (new GenerateDailyAnalyticsReportAction($events))->execute();

            // 3. Send Email
            (new SendAnalyticsEmailAction($app, $reportData, $date))->execute();

            $this->info('Report sent successfully.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to generate report: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
