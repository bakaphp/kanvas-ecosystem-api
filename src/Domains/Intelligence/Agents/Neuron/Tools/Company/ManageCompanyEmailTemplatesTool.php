<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;

#[AgentTool(name: 'Manage Company Email Templates', category: 'company')]
class ManageCompanyEmailTemplatesTool extends CompanyResourceTool
{
    protected string $name = 'manage_company_email_templates';
    protected ?string $description = 'List/get company email templates and same-app shared templates, create/update company templates, or copy one from a source company. Copy preserves Blade/HTML without rendering, subject, title, and recursively copies parents with new IDs. Editable fields: name, template, subject, title, parent_template_id (0 removes parent), is_system (boolean). Shared templates are read-only: copy creates a company override. Copy conflicts require explicit update or a new name; nothing is silently overwritten. Returned template_id_map maps source IDs to destination IDs. Does not copy notification type bindings or template-variable database records. Delete unsupported.';

    protected function resourceQuery(): Builder
    {
        return Templates::query()->fromApp($this->app)->whereIn('companies_id', [0, $this->company->getId()])->notDeleted();
    }

    protected function fields(): array
    {
        return ['name', 'template', 'subject', 'title', 'parent_template_id', 'is_system'];
    }

    protected function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'template' => 'sometimes|required|string', 'subject' => 'sometimes|nullable|string', 'title' => 'sometimes|nullable|string', 'parent_template_id' => 'sometimes|integer|min:0', 'is_system' => 'sometimes|boolean'];
    }

    protected function present($row): array
    {
        return [...parent::present($row), 'companies_id' => $row->companies_id];
    }

    protected function write(string $operation, ?int $id, array $data): array
    {
        if ($operation === 'delete') {
            throw new InvalidArgumentException('Email template deletion is not supported.');
        }
        $owned = $this->resourceQuery()->fromCompany($this->company);
        $record = $operation === 'update' ? (clone $owned)->findOrFail($id) : null;
        $this->assertUniqueName($owned, $data, $id);
        if (isset($data['parent_template_id']) && $data['parent_template_id'] > 0) {
            $this->parentChain((int) $data['parent_template_id'], $id ? [$id] : []);
        }
        if ($operation === 'create') {
            $this->requireName($data);
            Validator::make($data, ['template' => 'required|string'])->validate();
            $record = new CreateTemplateAction(new TemplateInput(
                app: $this->app,
                name: $data['name'],
                template: $data['template'],
                subject: $data['subject'] ?? null,
                title: $data['title'] ?? null,
                isSystem: (bool) ($data['is_system'] ?? false),
                company: $this->company,
                user: $this->user,
                parentTemplateId: $data['parent_template_id'] ?? 0,
            ))->execute(overwrite: false);
        } else {
            $record->fill($data)->saveOrFail();
        }

        return ['success' => true, 'record' => $this->present($record->fresh())];
    }

    protected function snapshot(int $id): array
    {
        return array_map(fn (Templates $row): array => parent::present($row), $this->parentChain($id));
    }

    /**
     * The template and its ancestors, nearest first. A cycle or a runaway chain fails instead of
     * looping; `$seen` lets an update exclude the row being re-parented.
     *
     * @return list<Templates>
     */
    private function parentChain(int $id, array $seen = []): array
    {
        $chain = [];
        while ($id > 0) {
            if (in_array($id, $seen, true) || count($seen) >= 20) {
                throw new InvalidArgumentException('Invalid or cyclic template parent chain.');
            }
            $seen[] = $id;
            $row = $this->resourceQuery()->findOrFail($id);
            $chain[] = $row;
            $id = (int) $row->parent_template_id;
        }

        return $chain;
    }

    protected function copy(array $snapshot, array $overrides): array
    {
        $sourceId = $snapshot[0]['id'];
        // An explicit destination parent replaces the source hierarchy.
        if (array_key_exists('parent_template_id', $overrides)) {
            $snapshot = [$snapshot[0]];
        }
        $map = [];
        foreach (array_reverse($snapshot) as $data) {
            $originalId = $data['id'];
            unset($data['id']);
            $data['parent_template_id'] = $map[$data['parent_template_id']] ?? 0;
            if ($originalId === $sourceId) {
                $data = array_replace($data, $overrides);
            }
            $existing = $this->resourceQuery()->fromCompany($this->company)->where('name', $data['name'])->first();
            if ($existing) {
                foreach ($data as $key => $value) {
                    if ($existing->getAttribute($key) != $value) {
                        throw new InvalidArgumentException('Destination template name conflict: ' . $data['name'] . '. Read and update explicitly or select a different name/parent.');
                    }
                }
                $result = ['success' => true, 'record' => $this->present($existing), 'reused' => true];
            } else {
                $result = $this->write('create', null, $data);
            }
            $map[$originalId] = $result['record']['id'];
        }

        return [...$result, 'source_id' => $sourceId, 'template_id_map' => $map];
    }
}
