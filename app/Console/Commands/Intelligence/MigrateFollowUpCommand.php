<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

class MigrateFollowUpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:intelligence:migrate-follow-up
                            {--pipeline-id= : Migrate only a specific pipeline by ID}
                            {--time-unit= : Force a specific time unit (minutes, hours, days)}
                            {--dry-run : Run the migration without saving to database}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Migrate pipeline stages notification_engagement_rules to Follow Up structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $pipelineId = $this->option('pipeline-id');
        $forceTimeUnit = $this->option('time-unit');

        // Validate time unit if provided
        if ($forceTimeUnit && ! in_array($forceTimeUnit, ['minutes', 'hours', 'days'])) {
            $this->error('Invalid time unit. Must be one of: minutes, hours, days');

            return 1;
        }

        if ($isDryRun) {
            $this->warn('Running in DRY-RUN mode. No data will be saved.');
        }

        if ($forceTimeUnit) {
            $this->info("Forcing time unit to: {$forceTimeUnit}");
        }

        $this->info('Starting Follow Up migration...');

        // Get pipelines to migrate
        $pipelines = $pipelineId
            ? Pipeline::where('id', $pipelineId)->get()
            : Pipeline::where('is_deleted', 0)->get();
        if ($pipelines->isEmpty()) {
            $this->error('No pipelines found to migrate.');

            return 1;
        }

        $this->info("Found {$pipelines->count()} pipeline(s) to migrate.");

        DB::beginTransaction();

        try {
            $totalFollowUps = 0;
            $totalDays = 0;
            $totalTemplates = 0;

            foreach ($pipelines as $pipeline) {
                $this->line("\n" . str_repeat('=', 60));
                $this->info("Processing Pipeline: {$pipeline->name} (ID: {$pipeline->id})");

                // Check if follow up already exists for this pipeline
                $existingFollowUp = FollowUp::where('pipelines_id', $pipeline->id)
                    ->where('follow_up_type', FollowUpTypeEnum::LEAD_FOLLOW_UP->value)
                    ->where('is_deleted', 0)
                    ->first();

                if ($existingFollowUp) {
                    $this->warn('  ⚠ Follow Up already exists for this pipeline. Skipping...');

                    continue;
                }

                // Get stages with notification_engagement_rules
                $stages = $pipeline->stages()
                    ->where('is_deleted', 0)
                    ->orderBy('weight')
                    ->get()
                    ->filter(function (PipelineStage $stage) {
                        $config = is_string($stage->config) ? json_decode($stage->config, true) : $stage->config;

                        return isset($config['notification_engagement_rules']);
                    });

                if ($stages->isEmpty()) {
                    $this->warn('  ⚠ No stages with notification_engagement_rules found. Skipping...');

                    continue;
                }

                $this->info("  Found {$stages->count()} stage(s) with engagement rules");

                // Create FollowUp
                $totalFollowUps++;
                if (! $isDryRun) {
                    $followUp = FollowUp::create([
                        'apps_id' => $pipeline->apps_id,
                        'companies_id' => $pipeline->companies_id,
                        'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
                        'pipelines_id' => $pipeline->id,
                        'name' => $pipeline->name . ' Follow Up',
                    ]);
                    $this->info("  ✓ Created Follow Up: {$followUp->name}");
                } else {
                    $this->info("  [DRY-RUN] Would create Follow Up: {$pipeline->name} Follow Up");
                    $followUp = null;
                }

                // Process each stage
                foreach ($stages as $stage) {
                    $config = is_string($stage->config) ? json_decode($stage->config, true) : $stage->config;
                    $engagementRules = $config['notification_engagement_rules'];

                    $this->line("\n    Processing Stage: {$stage->name}");

                    $dayValue = $engagementRules['day'] ?? 0;
                    $minutesNoResponse = $engagementRules['minutes_no_response'] ?? 0;
                    $sendMessage = $engagementRules['send_message'] ?? 0;
                    $templates = $engagementRules['templates'] ?? [];

                    // Convert minutes to appropriate time unit
                    [$timeValue, $timeUnit] = $this->convertMinutesToTimeUnit($minutesNoResponse, $forceTimeUnit);

                    $this->info("      - Day: {$dayValue}");
                    $this->info("      - Wait Time: {$timeValue} {$timeUnit}");
                    $this->info('      - Auto Send: ' . ($sendMessage ? 'Yes' : 'No'));
                    $this->info('      - Templates: ' . count($templates));

                    // Create FollowUpDay
                    $totalDays++;
                    if (! $isDryRun && $followUp) {
                        $followUpDay = FollowUpDay::create([
                            'follow_ups_id' => $followUp->id,
                            'pipeline_stages_id' => $stage->id,
                            'name' => "Day {$dayValue}",
                            'time_value' => $timeValue,
                            'time_unit' => $timeUnit,
                            'weight' => $stage->weight,
                            'calendar_day' => true,
                            'send_message' => (bool) $sendMessage,
                        ]);
                        $this->info("      ✓ Created Follow Up Day: {$followUpDay->name}");
                    } else {
                        $this->info("      [DRY-RUN] Would create Follow Up Day: Day {$dayValue}");
                        $followUpDay = null;
                    }

                    // Create templates
                    foreach ($templates as $channel => $templateContent) {
                        // Skip if channel is numeric (invalid template structure)
                        if (is_numeric($channel)) {
                            $this->warn("        ⚠ Skipping invalid template with numeric key: {$channel}");

                            continue;
                        }

                        // Skip if template content is empty
                        if (empty($templateContent)) {
                            $this->warn("        ⚠ Skipping empty template for channel: {$channel}");

                            continue;
                        }

                        $communicationChannel = $this->mapChannelType($channel);
                        $templateName = ucfirst($channel) . ' Template';

                        $totalTemplates++;
                        if (! $isDryRun && $followUpDay) {
                            FollowUpTemplate::create([
                                'follow_up_days_id' => $followUpDay->id,
                                'communication_channel' => $communicationChannel,
                                'name' => $templateName,
                                'template' => $templateContent,
                            ]);
                            $this->info("        ✓ Created Template: {$templateName} ({$communicationChannel})");
                        } else {
                            $this->info("        [DRY-RUN] Would create Template: {$templateName} ({$communicationChannel})");
                        }
                    }
                }
            }

            if (! $isDryRun) {
                DB::commit();
                $this->line("\n" . str_repeat('=', 60));
                $this->info('✓ Migration completed successfully!');
                $this->info("  Created: {$totalFollowUps} Follow Ups, {$totalDays} Days, {$totalTemplates} Templates");
            } else {
                DB::rollBack();
                $this->line("\n" . str_repeat('=', 60));
                $this->info('[DRY-RUN] Migration preview completed.');
                $this->info("  Would create: {$totalFollowUps} Follow Ups, {$totalDays} Days, {$totalTemplates} Templates");
                $this->warn('Run without --dry-run to execute the migration.');
            }

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Migration failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Convert minutes to appropriate time unit
     *
     * @param string|null $forceUnit Force a specific unit (minutes, hours, days)
     * @return array [value, unit]
     */
    private function convertMinutesToTimeUnit(int $minutes, ?string $forceUnit = null): array
    {
        if ($minutes == 0) {
            return [0, $forceUnit ?? 'minutes'];
        }

        // If a unit is forced, convert to that unit
        if ($forceUnit) {
            return match ($forceUnit) {
                'minutes' => [$minutes, 'minutes'],
                'hours' => [$minutes / 60, 'hours'],
                'days' => [$minutes / 1440, 'days'],
                default => [$minutes, 'minutes'],
            };
        }

        // Auto-convert to the most appropriate unit
        // Convert to hours if evenly divisible by 60
        if ($minutes % 60 === 0) {
            $hours = $minutes / 60;

            // Convert to days if evenly divisible by 24
            if ($hours % 24 === 0) {
                return [(int) ($hours / 24), 'days'];
            }

            return [(int) $hours, 'hours'];
        }

        return [$minutes, 'minutes'];
    }

    /**
     * Map channel type to communication channel
     */
    private function mapChannelType(string $channel): string
    {
        return match (strtolower($channel)) {
            'sms' => 'sms',
            'email' => 'email',
            'whatsapp' => 'whatsapp',
            default => $channel,
        };
    }
}
