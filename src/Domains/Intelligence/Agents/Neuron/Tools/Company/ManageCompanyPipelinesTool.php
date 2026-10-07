<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Actions\CreatePipelineAction;
use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline as PipelineData;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

#[AgentTool(name: 'Manage Company Pipelines', category: 'company')]
class ManageCompanyPipelinesTool extends CompanyResourceTool
{
    protected string $name = 'manage_company_pipelines';
    protected ?string $description = 'CRUD and copy company lead pipelines. Editable fields: name, weight (integer), is_default (boolean), slug (string/null). Get/list includes stages. Copy includes all stages and returns stage_id_map; configs containing source references must be adapted using manage_company_pipeline_stages. Updating a pipeline never replaces its stages. Delete requires a nondefault unused pipeline. Existing names are never overwritten by copy.';

    protected function resourceQuery(): Builder
    {
        return Pipeline::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()->with('stages');
    }

    protected function fields(): array
    {
        return ['name', 'weight', 'is_default', 'slug'];
    }

    protected function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'weight' => 'sometimes|integer', 'is_default' => 'sometimes|boolean', 'slug' => 'sometimes|nullable|string|max:255'];
    }

    protected function present($row): array
    {
        return [...parent::present($row), 'stages' => $row->stages->map(fn ($stage) => $stage->only(['id', 'name', 'weight', 'rotting_days', 'has_rotting_days', 'config']))->all()];
    }

    protected function write(string $operation, ?int $id, array $data): array
    {
        if ($operation === 'delete') {
            $record = $this->resourceQuery()->findOrFail($id);
            if ($record->isDefault() || $record->leads()->exists() || $record->followUps()->exists()
                || $record->stages->contains(fn ($stage) => $stage->followUpDays()->exists())) {
                throw new InvalidArgumentException('Cannot delete a default pipeline or one referenced by leads or follow-ups.');
            }

            return ['success' => (bool) $record->softDelete(), 'deleted_id' => $id];
        }
        $this->assertUniqueName($this->resourceQuery(), $data, $operation === 'update' ? $id : null);
        if ($operation === 'create') {
            $this->requireName($data);
            $branch = $this->company->defaultBranch ?? $this->company->branches()->firstOrFail();
            $record = new CreatePipelineAction(new PipelineData(
                branch: $branch,
                user: $this->user,
                systemModule: SystemModulesRepository::getByModelName(Lead::class, $this->app),
                name: $data['name'],
                weight: $data['weight'] ?? 0,
                isDefault: (bool) ($data['is_default'] ?? false),
                slug: $data['slug'] ?? null,
            ))->execute();
        } else {
            $record = $this->resourceQuery()->findOrFail($id);
            if ($record->isDefault() && array_key_exists('is_default', $data) && ! $data['is_default']) {
                throw new InvalidArgumentException('Set another pipeline as default first.');
            }
            $record->fill($data)->saveOrFail();
        }
        if ($record->is_default) {
            $this->resourceQuery()->where('id', '!=', $record->getId())->update(['is_default' => false]);
        }

        return ['success' => true, 'record' => $this->present($record->fresh())];
    }

    protected function copy(array $snapshot, array $overrides): array
    {
        $stages = $snapshot['stages'];
        unset($snapshot['stages']);
        // Copy never changes the destination default unless explicitly requested.
        $snapshot['is_default'] = false;
        $result = parent::copy($snapshot, $overrides);
        $pipeline = $this->resourceQuery()->findOrFail($result['record']['id']);
        $map = [];
        foreach ($stages as $stage) {
            $sourceId = $stage['id'];
            unset($stage['id']);
            $copy = $pipeline->stages()->create($stage);
            $map[$sourceId] = $copy->getId();
        }

        return [...$result, 'record' => $this->present($pipeline->fresh()), 'stage_id_map' => $map];
    }
}
