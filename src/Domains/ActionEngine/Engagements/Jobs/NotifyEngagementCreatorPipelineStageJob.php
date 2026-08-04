<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\ActionEngine\Engagements\Enums\EngagementStagePositionEnum;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Notifications\EngagementPipelineStageNotification;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;

class NotifyEngagementCreatorPipelineStageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Engagement $engagement,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->engagement->app);

        $position = $this->resolveStagePosition();
        if ($position === null) {
            return;
        }

        $creator = $this->engagement->user;
        if ($creator === null) {
            return;
        }

        $creator->notify($this->buildNotification($position));
    }

    private function resolveStagePosition(): ?EngagementStagePositionEnum
    {
        $stage = $this->engagement->stage;
        if ($stage === null) {
            return null;
        }

        $weights = PipelineStage::query()
            ->where('pipelines_id', $stage->pipelines_id)
            ->where('is_deleted', 0)
            ->pluck('weight');

        if ($weights->isEmpty()) {
            return null;
        }

        $current = (float) $stage->weight;

        // Last wins on ties so a single-stage pipeline reads as "completed".
        if ($current === (float) $weights->max()) {
            return EngagementStagePositionEnum::LAST;
        }

        if ($current === (float) $weights->min()) {
            return EngagementStagePositionEnum::FIRST;
        }

        return null;
    }

    private function buildNotification(EngagementStagePositionEnum $position): EngagementPipelineStageNotification
    {
        $stageName = (string) ($this->engagement->stage->name ?? $position->value);
        $action = (string) ($this->engagement->companyAction->name ?? '');

        $subject = $position === EngagementStagePositionEnum::LAST
            ? 'Completed - ' . $stageName
            : 'Started - ' . $stageName;

        return new EngagementPipelineStageNotification(
            $this->engagement,
            [
                'messageText' => trim($action . ' reached the ' . $stageName . ' stage'),
                'action' => $action,
                'subject' => $subject,
                'stage_position' => $position->value,
            ],
        );
    }
}
