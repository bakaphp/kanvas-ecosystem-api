<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Leads;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;

class BackfillLeadAiModeKeysCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:backfill-lead-ai-mode-keys
                            {company_id : The company ID (required)}
                            {app_id : The app ID (required)}
                            {--dry-run : Report planned changes without writing}';

    protected $description = 'Copy legacy V2 prefixed lead settings (internet_/phone_/showroom_ ai_mode and follow-up variants) into the generic ai_mode and ai_follow_up keys';

    private const PREFIXES = ['internet', 'phone', 'showroom'];

    public function handle(): int
    {
        $companyId = (int) $this->argument('company_id');
        $appId = (int) $this->argument('app_id');
        $isDryRun = (bool) $this->option('dry-run');

        $company = Companies::findOrFail($companyId);
        $app = Apps::findOrFail($appId);
        $this->overwriteAppService($app);

        $this->info("Company: {$company->name} (ID: {$company->getId()})");
        $this->info("App: {$app->name} (ID: {$app->getId()})");

        if ($isDryRun) {
            $this->warn('DRY-RUN: no changes will be written.');
        }

        $processed = 0;
        $aiModeCopied = 0;
        $followUpCopied = 0;
        $aiModeSkipped = 0;
        $followUpSkipped = 0;

        $leads = Lead::query()
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->with(['type', 'status'])
            ->cursor();

        foreach ($leads as $lead) {
            $processed++;

            $aiModeValue = $this->pickFirstNonEmpty($lead, $this->legacyAiModeKeys());
            if ($aiModeValue !== null) {
                $currentGeneric = $lead->get('ai_mode');
                if ($currentGeneric === null || $currentGeneric === '') {
                    if (! $isDryRun) {
                        $lead->set('ai_mode', $aiModeValue);
                    }
                    $aiModeCopied++;
                    $this->line("  Lead {$lead->getId()}: ai_mode <- {$aiModeValue}");
                } else {
                    $aiModeSkipped++;
                }
            }

            $followUpValue = $this->pickFirstNonEmpty($lead, $this->legacyFollowUpKeys());
            if ($followUpValue !== null) {
                $currentGeneric = $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value);
                if ($currentGeneric === null || $currentGeneric === '') {
                    if (! $isDryRun) {
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUpValue);
                    }
                    $followUpCopied++;
                    $this->line("  Lead {$lead->getId()}: ai_follow_up <- {$followUpValue}");
                } else {
                    $followUpSkipped++;
                }
            }
        }

        $this->info("Processed: {$processed}");
        $this->info("ai_mode copied: {$aiModeCopied} (skipped because generic already set: {$aiModeSkipped})");
        $this->info("ai_follow_up copied: {$followUpCopied} (skipped because generic already set: {$followUpSkipped})");

        return Command::SUCCESS;
    }

    private function pickFirstNonEmpty(Lead $lead, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $lead->get($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function legacyAiModeKeys(): array
    {
        return array_map(fn (string $prefix) => "{$prefix}_ai_mode", self::PREFIXES);
    }

    private function legacyFollowUpKeys(): array
    {
        $keys = [];
        foreach (self::PREFIXES as $prefix) {
            $keys[] = "{$prefix}_follow_up_mode";
            $keys[] = "{$prefix}_followup_closed-sold";
            $keys[] = "{$prefix}_followup_closed-not-sold";
        }

        return $keys;
    }
}
