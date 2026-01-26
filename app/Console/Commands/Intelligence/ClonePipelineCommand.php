<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

class ClonePipelineCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intelligence:clone-pipeline {pipeline_id} {new_name}';

    protected $description = 'Clone a pipeline with all its stages, follow ups, days and templates';

    public function handle(): void
    {
        $pipelineId = $this->argument('pipeline_id');
        $newName = $this->argument('new_name');

        // Find the original pipeline
        $originalPipeline = Pipeline::with([
            'stages',
            'followUps.days.templates'
        ])->find($pipelineId);

        if (! $originalPipeline) {
            $this->error("Pipeline with ID {$pipelineId} not found.");
            return;
        }

        $this->info("Cloning pipeline: {$originalPipeline->name}");
        $this->info("New pipeline name: {$newName}");

        // Start transaction
        \DB::beginTransaction();

        try {
            // Clone the pipeline
            $newPipeline = $this->clonePipeline($originalPipeline, $newName);
            $this->info("✓ Pipeline cloned: {$newPipeline->name} (ID: {$newPipeline->id})");

            // Clone stages and keep mapping
            $stageMapping = $this->cloneStages($originalPipeline, $newPipeline);
            $this->info("✓ Cloned " . \count($stageMapping) . " stages");

            // Clone follow ups, days and templates
            $this->cloneFollowUps($originalPipeline, $newPipeline, $stageMapping);

            \DB::commit();

            $this->info('');
            $this->info("✓ Pipeline cloned successfully!");
            $this->info("New Pipeline ID: {$newPipeline->id}");
            $this->info("Total Stages: " . \count($stageMapping));
        } catch (\Exception $e) {
            \DB::rollBack();
            $this->error("Error cloning pipeline: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
        }
    }

    protected function clonePipeline(Pipeline $original, string $newName): Pipeline
    {
        $newPipeline = new Pipeline();
        $newPipeline->apps_id = $original->apps_id;
        $newPipeline->companies_id = $original->companies_id;
        $newPipeline->users_id = $original->users_id;
        $newPipeline->system_modules_id = $original->system_modules_id;
        $newPipeline->name = $newName;
        $newPipeline->weight = $original->weight;
        $newPipeline->is_default = 0; // Never set as default
        $newPipeline->is_deleted = 0;
        $newPipeline->save();

        return $newPipeline;
    }

    protected function cloneStages(Pipeline $original, Pipeline $new): array
    {
        $stageMapping = [];

        foreach ($original->stages as $originalStage) {
            $newStage = new PipelineStage();
            $newStage->pipelines_id = $new->id;
            $newStage->name = $originalStage->name;
            $newStage->has_rotting_days = $originalStage->has_rotting_days;
            $newStage->rotting_days = $originalStage->rotting_days;
            $newStage->config = $originalStage->config;
            $newStage->weight = $originalStage->weight;
            $newStage->is_deleted = 0;
            $newStage->save();

            // Map old stage ID to new stage ID
            $stageMapping[$originalStage->id] = $newStage->id;

            $this->line("  - Stage: {$originalStage->name} (ID: {$originalStage->id} -> {$newStage->id})");
        }

        return $stageMapping;
    }

    protected function cloneFollowUps(Pipeline $original, Pipeline $new, array $stageMapping): void
    {
        $totalFollowUps = 0;
        $totalDays = 0;
        $totalTemplates = 0;

        foreach ($original->followUps as $originalFollowUp) {
            // Clone FollowUp
            $newFollowUp = new FollowUp();
            $newFollowUp->apps_id = $originalFollowUp->apps_id;
            $newFollowUp->companies_id = $originalFollowUp->companies_id;
            $newFollowUp->follow_up_type = $originalFollowUp->follow_up_type;
            $newFollowUp->pipelines_id = $new->id;
            $newFollowUp->name = $originalFollowUp->name;
            $newFollowUp->config = $originalFollowUp->config;
            $newFollowUp->is_deleted = 0;
            $newFollowUp->save();

            $totalFollowUps++;
            $this->line("  - Follow Up: {$originalFollowUp->name} (ID: {$originalFollowUp->id} -> {$newFollowUp->id})");

            // Clone FollowUpDays
            foreach ($originalFollowUp->days as $originalDay) {
                // Get the new stage ID from mapping
                $newStageId = $stageMapping[$originalDay->pipeline_stages_id] ?? null;

                if (! $newStageId) {
                    $this->warn("    ⚠ Stage mapping not found for day: {$originalDay->name}. Skipping...");
                    continue;
                }

                $newDay = new FollowUpDay();
                $newDay->follow_ups_id = $newFollowUp->id;
                $newDay->pipeline_stages_id = $newStageId;
                $newDay->name = $originalDay->name;
                $newDay->time_value = $originalDay->time_value;
                $newDay->time_unit = $originalDay->time_unit;
                $newDay->weight = $originalDay->weight;
                $newDay->calendar_day = $originalDay->calendar_day;
                $newDay->move_to_stage_id = isset($stageMapping[$originalDay->move_to_stage_id])
                    ? $stageMapping[$originalDay->move_to_stage_id]
                    : $originalDay->move_to_stage_id;
                $newDay->send_message = $originalDay->send_message;
                $newDay->is_deleted = 0;
                $newDay->save();

                $totalDays++;
                $this->line("    - Day: {$originalDay->name} (ID: {$originalDay->id} -> {$newDay->id})");

                // Clone FollowUpTemplates
                foreach ($originalDay->templates as $originalTemplate) {
                    $newTemplate = new FollowUpTemplate();
                    $newTemplate->follow_up_days_id = $newDay->id;
                    $newTemplate->communication_channel = $originalTemplate->communication_channel;
                    $newTemplate->name = $originalTemplate->name;
                    $newTemplate->template = $originalTemplate->template;
                    $newTemplate->is_deleted = 0;
                    $newTemplate->save();

                    $totalTemplates++;
                    $this->line("      - Template: {$originalTemplate->name} (ID: {$originalTemplate->id} -> {$newTemplate->id})");
                }
            }
        }

        $this->info("✓ Cloned {$totalFollowUps} follow ups, {$totalDays} days, {$totalTemplates} templates");
    }
}
