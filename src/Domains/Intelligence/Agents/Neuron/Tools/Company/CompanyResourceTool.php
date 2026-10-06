<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use stdClass;

/** Company selection always goes through the app administrator boundary, including reads. */
abstract class CompanyResourceTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use RunsInExplicitCompany;
    use TrackByInputs;

    abstract protected function resourceQuery(): Builder;
    abstract protected function fields(): array;
    abstract protected function rules(): array;
    abstract protected function write(string $operation, ?int $id, array $data): array;

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'company_uuid',
                type: PropertyType::STRING,
                description: 'Company UUID for this operation; destination for copy. Requires the app-scoped Company Configuration Administrator and a human app administrator.',
                required: true,
            ),
            new ToolProperty(
                name: 'operation',
                type: PropertyType::STRING,
                description: 'list, get, create, update, delete or copy. See tool description for supported operations.',
                required: true,
            ),
            new ToolProperty(
                name: 'id',
                type: PropertyType::INTEGER,
                description: 'Record ID for get/update/delete; SOURCE record ID for copy.',
                required: false,
            ),
            new ToolProperty(
                name: 'data_json',
                type: PropertyType::STRING,
                description: 'JSON object containing only the editable fields listed in the description. Partial updates preserve omitted fields. For copy these are destination overrides.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_company_uuid',
                type: PropertyType::STRING,
                description: 'Required for copy: authorized source company in the same app.',
                required: false,
            ),
            new ToolProperty(
                name: 'page',
                type: PropertyType::INTEGER,
                description: 'List page, starting at 1. Follow has_more until false.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $company_uuid,
        string $operation,
        ?int $id = null,
        ?string $data_json = null,
        ?string $source_company_uuid = null,
        ?int $page = null,
    ): array {
        $page ??= 1;
        return $this->inExplicitCompany($company_uuid, $this->contextAgent(), function (self $tool) use ($operation, $id, $data_json, $source_company_uuid, $page): array {
            try {
                if (! in_array($operation, ['list', 'get', 'create', 'update', 'delete', 'copy'], true) || $page < 1) {
                    throw new InvalidArgumentException('Invalid operation or page.');
                }
                if (in_array($operation, ['get', 'update', 'delete', 'copy'], true) && ($id === null || $id < 1)) {
                    throw new InvalidArgumentException('A positive record id is required.');
                }
                $object = json_decode($data_json ?? '{}', false, 64, JSON_THROW_ON_ERROR);
                if (! $object instanceof stdClass) {
                    throw new InvalidArgumentException('data_json must be a JSON object.');
                }
                $data = json_decode($data_json ?? '{}', true, 64, JSON_THROW_ON_ERROR);
                if (array_diff(array_keys($data), $tool->fields()) !== []) {
                    throw new InvalidArgumentException('Unsupported fields. Allowed: ' . implode(', ', $tool->fields()));
                }
                Validator::make($data, $tool->rules())->validate();
                if ($operation === 'list') {
                    $rows = $tool->resourceQuery()->orderBy('id')->skip(($page - 1) * 50)->take(51)->get();
                    return ['success' => true, 'records' => $rows->take(50)->map(fn ($row) => $tool->present($row))->all(), 'page' => $page, 'has_more' => $rows->count() > 50];
                }
                if ($operation === 'get') {
                    return ['success' => true, 'record' => $tool->present($tool->resourceQuery()->findOrFail($id))];
                }
                if ($operation === 'copy') {
                    if (! $source_company_uuid || $source_company_uuid === $tool->company->uuid) {
                        throw new InvalidArgumentException('Copy requires a different source_company_uuid.');
                    }
                    $snapshot = $tool->inExplicitCompany($source_company_uuid, $tool->contextAgent(), fn (self $source): array => ['success' => true, 'snapshot' => $source->snapshot($id)]);
                    if (! ($snapshot['success'] ?? false)) {
                        return $snapshot;
                    }
                    return $tool->resourceQuery()->getModel()->getConnection()->transaction(fn (): array => $tool->copy($snapshot['snapshot'], $data));
                }
                return $tool->resourceQuery()->getModel()->getConnection()->transaction(fn (): array => $tool->write($operation, $id, $data));
            } catch (ValidationException $e) {
                return ['success' => false, 'error' => $e->validator->errors()->toArray()];
            } catch (InvalidArgumentException|JsonException $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            } catch (ModelNotFoundException) {
                return ['success' => false, 'error' => 'Record not found in the selected app and company.'];
            }
        });
    }

    protected function present($row): array
    {
        return $row->only(['id', ...$this->fields()]);
    }

    protected function snapshot(int $id): array
    {
        return $this->present($this->resourceQuery()->findOrFail($id));
    }

    protected function copy(array $snapshot, array $overrides): array
    {
        $sourceId = $snapshot['id'];
        unset($snapshot['id']);
        return [...$this->write('create', null, array_replace($snapshot, $overrides)), 'source_id' => $sourceId];
    }

    protected function requireName(array $data): void
    {
        Validator::make($data, ['name' => 'required|string|max:255'])->validate();
    }

    protected function assertUniqueName(Builder $query, array $data, ?int $id = null): void
    {
        if (isset($data['name']) && $query->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw new InvalidArgumentException('That name already exists. Read and update its destination ID explicitly; nothing was overwritten.');
        }
    }
}
