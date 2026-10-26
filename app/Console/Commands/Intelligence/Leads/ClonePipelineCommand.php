<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Leads;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\FollowUp\Actions\ClonePipelineWithFollowUpsAction;
use Throwable;

class ClonePipelineCommand extends Command
{
    use KanvasJobsTrait;

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

        $originalPipeline = Pipeline::find($pipelineId);

        if (! $originalPipeline) {
            $this->error("Pipeline with ID {$pipelineId} not found.");

            return;
        }

        /** @var Apps $app */
        $app = Apps::getById((int) $originalPipeline->apps_id);
        $this->overwriteAppService($app);

        $this->info("Cloning pipeline: {$originalPipeline->name}");
        $this->info("New pipeline name: {$newName}");

        try {
            $clone = new ClonePipelineWithFollowUpsAction($originalPipeline, $newName);
            $newPipeline = $clone->execute();
        } catch (Throwable $e) {
            $this->error("Error cloning pipeline: {$e->getMessage()}");
            $this->error($e->getTraceAsString());

            return;
        }

        $counts = $clone->counts();

        $this->info('');
        $this->info('✓ Pipeline cloned successfully!');
        $this->info("New Pipeline ID: {$newPipeline->id}");
        $this->info('Total Stages: ' . count($clone->stageMapping()));
        $this->info("✓ Cloned {$counts['follow_ups']} follow ups, {$counts['days']} days, {$counts['templates']} templates");
    }
}
