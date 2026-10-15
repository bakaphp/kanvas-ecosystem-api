<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Actions\CreateStagePipelineAction;
use Kanvas\Guild\Pipelines\Actions\StageCounterAction;
use Kanvas\Guild\Pipelines\Actions\UpdateStagePipelineAction;
use Kanvas\Guild\Pipelines\DataTransferObject\PipelineStage as StageData;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;

#[AgentTool(name: 'Manage Company Pipeline Stages', category: 'company')]
class ManageCompanyPipelineStagesTool extends CompanyResourceTool
{
    protected string $name = 'manage_company_pipeline_stages';
    protected ?string $description = 'CRUD and copy stages, scoped through their company pipeline. Editable fields: name, pipelines_id, weight, rotting_days (nonnegative integer), has_rotting_days (0/1), config (object/array/null). Create and copy require a DESTINATION pipelines_id. Existing stages cannot move to another pipeline. Delete refuses stages used by leads or follow-ups. List all pages or get the pipeline to see its stages.';

    protected function resourceQuery(): Builder
    {
        return PipelineStage::query()->whereHas('pipeline', fn ($query) => $query->fromApp($this->app)->fromCompany($this->company)->notDeleted());
    }

    protected function fields(): array
    {
        return ['name', 'pipelines_id', 'weight', 'rotting_days', 'has_rotting_days', 'config'];
    }

    protected function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'pipelines_id' => 'sometimes|integer|min:1', 'weight' => 'sometimes|integer', 'rotting_days' => 'sometimes|integer|min:0', 'has_rotting_days' => 'sometimes|integer|in:0,1', 'config' => 'sometimes|nullable|array'];
    }

    protected function write(string $operation, ?int $id, array $data): array
    {
        $record = $operation === 'create' ? null : $this->resourceQuery()->findOrFail($id);
        $pipelineId = $data['pipelines_id'] ?? $record?->pipelines_id;
        $pipeline = Pipeline::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()->findOrFail($pipelineId);
        if ($record && (int) $record->pipelines_id !== (int) $pipeline->getId()) {
            throw new InvalidArgumentException('A stage cannot move to another pipeline.');
        }
        if ($operation === 'delete') {
            if (Lead::where('pipeline_stage_id', $record->getId())->exists() || $record->followUpDays()->exists()) {
                throw new InvalidArgumentException('Cannot delete a stage referenced by leads or follow-ups.');
            }
            $record->delete();
            new StageCounterAction($pipeline->fresh())->execute();

            return ['success' => true, 'deleted_id' => $id];
        }
        $this->assertUniqueName($pipeline->stages()->getQuery(), $data, $id);
        if ($operation === 'create') {
            $this->requireName($data);
            $record = new CreateStagePipelineAction(StageData::viaRequest($pipeline, $data))->execute();
        } else {
            new UpdateStagePipelineAction($record, StageData::viaRequest($pipeline, array_replace($this->present($record), $data)))->execute();
        }
        // The domain DTO does not yet expose config/has_rotting_days. Preserve explicit zero weight too.
        $record->fill(array_intersect_key($data, array_flip(['config', 'has_rotting_days', 'weight'])))->saveOrFail();

        return ['success' => true, 'record' => $this->present($record->fresh())];
    }

    protected function copy(array $snapshot, array $overrides): array
    {
        Validator::make($overrides, ['pipelines_id' => 'required|integer|min:1'])->validate();

        return parent::copy($snapshot, $overrides);
    }
}
