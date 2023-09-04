<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kanvas\Guild\Pipelines\DataTransferObject\PipelineStage;
use Kanvas\Guild\Pipelines\Models\PipelineStage as ModelsPipelineStage;

class UpdateStagePipelineAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly ModelsPipelineStage $stage,
        protected readonly PipelineStage $stageData,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): ModelsPipelineStage
    {
        //stage have to have unique name
        $data = [
            'name' => $this->stageData->name,
            'pipelines_id' => $this->stageData->pipeline->getId(),
        ];

        $validator = Validator::make($data, [
            'name' => [
                'required',
                Rule::unique('pipelines_stages')->where(function ($query) use ($data) {
                    return $query->where('pipelines_id', $data['pipeline_id']);
                })->ignore($this->stage->getId()),
            ],
            'pipelines_id' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->stage->name = $this->stageData->name;
        $this->stage->weight = $this->stageData->weight;
        $this->stage->rotting_days = $this->stageData->rotting_days;
        $this->stage->saveOrFail();


        return $this->stage;
    }
}
