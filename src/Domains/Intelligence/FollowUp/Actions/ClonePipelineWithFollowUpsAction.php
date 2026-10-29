<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

/**
 * Copies a pipeline with its stages and every follow-up, day and template, into the source's own
 * company or another one. Stage ids are remapped, so a day's stage and its move-to stage point at
 * the copy's stages, never the source's.
 */
final class ClonePipelineWithFollowUpsAction
{
    /**
     * @var array<int, int> source stage id → copy's stage id
     */
    private array $stageMapping = [];

    private int $followUps = 0;

    private int $days = 0;

    private int $templates = 0;

    public function __construct(
        private readonly Pipeline $source,
        private readonly string $name,
        private readonly ?Companies $company = null
    ) {
    }

    public function execute(): Pipeline
    {
        $source = $this->source->loadMissing(['stages', 'followUps.days.templates']);

        return DB::connection($source->getConnectionName())->transaction(function () use ($source): Pipeline {
            $pipeline = new Pipeline();
            $pipeline->apps_id = $source->apps_id;
            $pipeline->companies_id = $this->company?->getId() ?? $source->companies_id;
            $pipeline->users_id = $this->company?->users_id ?? $source->users_id;
            $pipeline->system_modules_id = $source->system_modules_id;
            $pipeline->name = $this->name;
            $pipeline->weight = $source->weight;
            $pipeline->is_default = 0;
            $pipeline->is_deleted = 0;
            $pipeline->save();

            foreach ($source->stages as $sourceStage) {
                $stage = new PipelineStage();
                $stage->pipelines_id = $pipeline->getId();
                $stage->name = $sourceStage->name;
                $stage->has_rotting_days = $sourceStage->has_rotting_days;
                $stage->rotting_days = $sourceStage->rotting_days;
                $stage->config = $sourceStage->config;
                $stage->weight = $sourceStage->weight;
                $stage->is_deleted = 0;
                $stage->save();

                $this->stageMapping[$sourceStage->getId()] = $stage->getId();
            }

            foreach ($source->followUps as $sourceFollowUp) {
                $this->cloneFollowUp($sourceFollowUp, $pipeline);
            }

            return $pipeline;
        });
    }

    /**
     * @return array<int, int>
     */
    public function stageMapping(): array
    {
        return $this->stageMapping;
    }

    /**
     * @return array{follow_ups: int, days: int, templates: int}
     */
    public function counts(): array
    {
        return ['follow_ups' => $this->followUps, 'days' => $this->days, 'templates' => $this->templates];
    }

    private function cloneFollowUp(FollowUp $source, Pipeline $pipeline): void
    {
        $followUp = new FollowUp();
        $followUp->apps_id = $source->apps_id;
        $followUp->companies_id = $this->company?->getId() ?? $source->companies_id;
        $followUp->follow_up_type = $source->follow_up_type;
        $followUp->pipelines_id = $pipeline->getId();
        $followUp->name = $source->name;
        $followUp->config = $source->config;
        $followUp->is_deleted = 0;
        $followUp->save();
        $this->followUps++;

        foreach ($source->days as $sourceDay) {
            $stageId = $this->stageMapping[$sourceDay->pipeline_stages_id] ?? null;

            // A day on a stage outside this pipeline has nowhere to live in the copy.
            if ($stageId === null) {
                continue;
            }

            $day = new FollowUpDay();
            $day->follow_ups_id = $followUp->getId();
            $day->pipeline_stages_id = $stageId;
            $day->name = $sourceDay->name;
            $day->time_value = $sourceDay->time_value;
            $day->time_unit = $sourceDay->time_unit;
            $day->weight = $sourceDay->weight;
            $day->calendar_day = $sourceDay->calendar_day;
            $day->move_to_stage_id = $this->stageMapping[$sourceDay->move_to_stage_id] ?? $sourceDay->move_to_stage_id;
            $day->send_message = $sourceDay->send_message;
            $day->is_deleted = 0;
            $day->save();
            $this->days++;

            foreach ($sourceDay->templates as $sourceTemplate) {
                $template = new FollowUpTemplate();
                $template->follow_up_days_id = $day->getId();
                $template->communication_channel = $sourceTemplate->communication_channel;
                $template->name = $sourceTemplate->name;
                $template->template = $sourceTemplate->template;
                $template->is_deleted = 0;
                $template->save();
                $this->templates++;
            }
        }
    }
}
