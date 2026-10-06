<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;

#[AgentTool(name: 'Manage Company Lead Types', category: 'company')]
class ManageCompanyLeadTypesTool extends CompanyResourceTool
{
    protected string $name = 'manage_company_lead_types';
    protected ?string $description = 'List, get, create, update and copy lead types between authorized companies. Editable fields: name, description, is_active (0/1), config (JSON object/array or null). Copy returns source_id and destination record.id. Adapt company-specific IDs in config using destination overrides. Delete is not supported.';

    protected function resourceQuery(): Builder
    {
        return LeadType::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted();
    }

    protected function fields(): array
    {
        return ['name', 'description', 'is_active', 'config'];
    }

    protected function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'description' => 'sometimes|string', 'is_active' => 'sometimes|integer|in:0,1', 'config' => 'sometimes|nullable|array'];
    }

    protected function write(string $operation, ?int $id, array $data): array
    {
        if ($operation === 'delete') {
            throw new InvalidArgumentException('Lead type deletion is not supported; update is_active to 0 instead.');
        }
        $this->assertUniqueName($this->resourceQuery(), $data, $operation === 'update' ? $id : null);
        if ($operation === 'create') {
            $this->requireName($data);
            $record = new CreateLeadTypeAction(new LeadTypeData(
                apps: $this->app,
                companies: $this->company,
                name: $data['name'],
                description: $data['description'] ?? '',
                is_active: $data['is_active'] ?? 1,
                config: $data['config'] ?? null,
            ))->execute();
        } else {
            $record = $this->resourceQuery()->findOrFail($id);
            $record->fill($data)->saveOrFail();
        }
        return ['success' => true, 'record' => $this->present($record->fresh())];
    }
}
