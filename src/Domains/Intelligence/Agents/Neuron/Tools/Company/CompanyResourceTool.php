<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Closure;
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

/** Company selection always goes through the app administrator boundary, including reads. */
abstract class CompanyResourceTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use RunsInExplicitCompany;
    use TrackByInputs;

    private const int PAGE_SIZE = 50;

    private const array OPERATIONS = ['list', 'get', 'create', 'update', 'delete', 'copy'];

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
                $tool->assertValidRequest($operation, $id, $page);
                $data = $tool->decodeData($data_json);

                return match ($operation) {
                    'list' => $tool->list($page),
                    'get' => ['success' => true, 'record' => $tool->present($tool->resourceQuery()->findOrFail($id))],
                    'copy' => $tool->copyFrom($source_company_uuid, (int) $id, $data),
                    default => $tool->transaction(fn (): array => $tool->write($operation, $id, $data)),
                };
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

    private function assertValidRequest(string $operation, ?int $id, int $page): void
    {
        if (! in_array($operation, self::OPERATIONS, true) || $page < 1) {
            throw new InvalidArgumentException('Invalid operation or page.');
        }
        if (in_array($operation, ['get', 'update', 'delete', 'copy'], true) && ($id === null || $id < 1)) {
            throw new InvalidArgumentException('A positive record id is required.');
        }
    }

    private function decodeData(?string $json): array
    {
        $data = json_decode($json ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidArgumentException('data_json must be a JSON object.');
        }
        if (array_diff(array_keys($data), $this->fields()) !== []) {
            throw new InvalidArgumentException('Unsupported fields. Allowed: ' . implode(', ', $this->fields()));
        }
        Validator::make($data, $this->rules())->validate();

        return $data;
    }

    private function list(int $page): array
    {
        $rows = $this->resourceQuery()->orderBy('id')->skip(($page - 1) * self::PAGE_SIZE)->take(self::PAGE_SIZE + 1)->get();

        return [
            'success' => true,
            'records' => $rows->take(self::PAGE_SIZE)->map(fn ($row) => $this->present($row))->all(),
            'page' => $page,
            'has_more' => $rows->count() > self::PAGE_SIZE,
        ];
    }

    private function copyFrom(?string $sourceCompanyUuid, int $id, array $overrides): array
    {
        if (! $sourceCompanyUuid || $sourceCompanyUuid === $this->company->uuid) {
            throw new InvalidArgumentException('Copy requires a different source_company_uuid.');
        }

        $snapshot = $this->inExplicitCompany($sourceCompanyUuid, $this->contextAgent(), fn (self $source): array => ['success' => true, 'snapshot' => $source->snapshot($id)]);
        if (! ($snapshot['success'] ?? false)) {
            return $snapshot;
        }

        return $this->transaction(fn (): array => $this->copy($snapshot['snapshot'], $overrides));
    }

    private function transaction(Closure $operation): array
    {
        return $this->resourceQuery()->getModel()->getConnection()->transaction($operation);
    }
}
