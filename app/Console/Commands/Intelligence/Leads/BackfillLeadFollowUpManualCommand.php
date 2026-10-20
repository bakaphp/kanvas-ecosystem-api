<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Leads;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;

/**
 * Before ai_follow_up_manual existed, MANUAL_OFF / MANUAL_FON only set lead_ai_mode_is_manual.
 * Without this carry-over the lead-type default would override the follow-up a human chose.
 */
class BackfillLeadFollowUpManualCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:backfill-lead-follow-up-manual
                            {company_id : The company ID (required)}
                            {app_id : The app ID (required)}
                            {--dry-run : Report planned changes without writing}';

    protected $description = 'Pin ai_follow_up_manual on leads whose AI mode is manual and that carry a stored ai_follow_up';

    public function handle(): int
    {
        $company = Companies::findOrFail((int) $this->argument('company_id'));
        $app = Apps::findOrFail((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Company: {$company->name} (ID: {$company->getId()})");
        $this->info("App: {$app->name} (ID: {$app->getId()})");

        if ($isDryRun) {
            $this->warn('DRY-RUN: no changes will be written.');
        }

        $processed = 0;
        $pinned = 0;

        $leads = Lead::query()
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->cursor();

        foreach ($leads as $lead) {
            $processed++;

            if (! (bool) $lead->get(ConfigurationEnum::AI_MODE_IS_MANUAL->value)
                || (bool) $lead->get(ConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value)
                || blank($lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value))) {
                continue;
            }

            if (! $isDryRun) {
                $lead->set(ConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value, true);
            }
            $pinned++;
            $this->line("  Lead {$lead->getId()}: ai_follow_up_manual <- true");
        }

        $this->info("Processed: {$processed}");
        $this->info("Pinned: {$pinned}");

        return Command::SUCCESS;
    }
}
