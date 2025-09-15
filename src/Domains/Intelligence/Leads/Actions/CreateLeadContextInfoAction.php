<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Actions;

use Exception;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class CreateLeadContextInfoAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $params): array
    {
        $leadTypePipeline = $this->lead->type?->name;

        //set the bdc pipeline to follow based on the lead type
        if ($leadTypePipeline === null || ! isset($params['pipelinesMapping'][$leadTypePipeline])) {
            throw new Exception('No pipeline mapping found for lead type ' . $leadTypePipeline . ', please configure it.');
        }

        $pipelineId = $params['pipelinesMapping'][$leadTypePipeline];

        $pipeline = Pipeline::fromCompany($this->lead->company)
            ->fromApp($this->lead->app)
            ->where('id', $pipelineId)
            ->where('is_deleted', 0)
            ->firstOrFail();

        $firstPipelineStage = $pipeline->stages->firstOrFail();
        $this->lead->pipeline_id = $pipeline->id;
        $this->lead->pipeline_stage_id = $firstPipelineStage->id;
        $this->lead->saveOrFail();

        $pipelineStageConfig = $firstPipelineStage->config;

        if (empty($pipelineStageConfig)) {
            throw new Exception('No configuration found for pipeline stage ' . $firstPipelineStage->name . ', please configure it.');
        }

        $contextInvocableActions = $pipelineStageConfig['actions'];

        if (empty($contextInvocableActions)) {
            throw new Exception('No actions found for pipeline stage ' . $firstPipelineStage->name . ', please configure it.');
        }

        $leadContext = [];
        foreach ($contextInvocableActions as $action) {
            $actionClass = $action['class'];
            $actionParams = $action['params'] ?? [];
            $contactIndex = $action['contact_index'] ?? null;

            if (empty($actionClass)) {
                throw new Exception('No action class found for action in pipeline stage ' . $firstPipelineStage->name . ', please configure it.');
            }

            if (empty($contactIndex)) {
                throw new Exception('No contact index found for action ' . $actionClass . ' in pipeline stage ' . $firstPipelineStage->name . ', please configure it.');
            }

            $actionInstance = new $actionClass($this->lead);
            $leadContext[$contactIndex] = $actionInstance->execute($actionParams);
        }

        if (empty($leadContext)) {
            return [];
        }

        $this->lead->set(
            ConfigurationEnum::LEAD_CONTEXT_INFO->value,
            $leadContext
        );

        return $leadContext;
    }
}
