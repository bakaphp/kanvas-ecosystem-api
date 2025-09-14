<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Exception;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Guild\Leads\Models\Lead;
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
        $pipeline = Pipeline::where('companies_id', $this->lead->companies_id)
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

            $actionInstance = new $actionClass($lead);
            $leadContext[$contactIndex] = $actionInstance->execute($actionParams);
        }

        if (empty($leadContext)) {
            return ['success' => false, 'message' => 'No context generated for the lead.'];
        }

        $this->lead->set(
            ConfigurationEnum::LEAD_CONTEXT_INFO->value,
            $leadContext
        );

        //get the first message
        //send the first message

        //move to stage 2 of the pipeline
        $this->lead->moveToNextPipelineStage();

        return ['success' => true, 'message' => 'Lead handed off to ' . $leadOwner->id];
    }
}
