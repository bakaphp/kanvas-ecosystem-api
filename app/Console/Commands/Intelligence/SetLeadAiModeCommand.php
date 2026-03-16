<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;

class SetLeadAiModeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:set-lead-ai-mode
                            {company_id : The company ID (required)}
                            {--app_id= : The app ID (optional, processes all apps if not specified)}
                            {--from=2026-03-14 : Start date (default: 2026-03-14)}
                            {--to=2026-03-16 : End date (default: 2026-03-16)}
                            {--dry-run : Run without making changes}';

    protected $description = 'Set ai_mode to SUPPORT and ai_follow_up to 1 for leads that do not have ai_mode set';

    public function handle(): int
    {
        $companyId = (int) $this->argument('company_id');
        $appId = $this->option('app_id');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $isDryRun = $this->option('dry-run');

        $company = Companies::findOrFail($companyId);
        $this->info("Processing company: {$company->name} (ID: {$company->id})");

        $apps = $appId
            ? Apps::where('id', (int) $appId)->get()
            : Apps::all();

        if ($isDryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be made.');
        }

        $this->info("Date range: {$fromDate} to {$toDate}");

        $totalProcessed = 0;
        $totalUpdated = 0;

        foreach ($apps as $app) {
            $this->overwriteAppService($app);
            $this->info("Processing app: {$app->name} (ID: {$app->id})");

            $leads = Lead::where('companies_id', $company->getId())
                ->where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->whereBetween('created_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
                ->whereHas(
                    'status',
                    function ($query) {
                        $query->where(function ($q) {
                            $q->where('name', 'like', '%active%')
                                ->orWhere('name', 'like', '%created%');
                        })->where('name', 'not like', '%inactive%');
                    }
                )
                ->cursor();

            foreach ($leads as $lead) {
                $totalProcessed++;
                $currentAiMode = $lead->get('ai_mode');

                if ($currentAiMode !== null && $currentAiMode !== '') {
                    continue;
                }

                if ($isDryRun) {
                    $this->line("  [DRY-RUN] Would update Lead ID: {$lead->id}");
                } else {
                    $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                    $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);
                    $this->line("  Updated Lead ID: {$lead->id}");
                }

                $totalUpdated++;
            }

            $this->info("App {$app->name}: Processed {$totalProcessed} leads, updated {$totalUpdated}");
        }

        $this->info("Completed. Total processed: {$totalProcessed}, Total updated: {$totalUpdated}");

        return Command::SUCCESS;
    }
}
