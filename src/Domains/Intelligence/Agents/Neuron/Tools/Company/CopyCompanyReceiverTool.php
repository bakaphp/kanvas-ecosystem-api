<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Company;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

#[AgentTool(name: 'Copy Company Receiver', category: 'workflow')]
class CopyCompanyReceiverTool extends CompanyResourceTool
{
    use ResolvesWorkflowCatalogForTool;

    protected string $name = 'copy_company_receiver';
    protected ?string $description = 'List/get company webhook receivers or copy one to another authorized company. Operations: list, get, copy only. Copy uses company_uuid DESTINATION, source_company_uuid SOURCE and id SOURCE receiver ID. Optional data_json overrides: name, description, configuration, is_active, run_async. If the source has configuration, explicitly supply the complete adapted destination configuration; never reuse source agent/pipeline/stage/lead type IDs. Credentials are redacted and cannot be supplied in chat. Copy preserves the receiver handler and run_async, generates a NEW UUID/URL, and defaults to inactive unless is_active is explicitly requested. Does not copy history, change the source or register the new URL with an external provider. Existing destination names cause a conflict, never an overwrite.';

    protected function resourceQuery(): Builder
    {
        return ReceiverWebhook::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()->with('action');
    }

    protected function fields(): array
    {
        return ['name', 'description', 'configuration', 'is_active', 'run_async'];
    }

    protected function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'configuration' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'run_async' => 'sometimes|boolean',
        ];
    }

    protected function present($row): array
    {
        return [
            ...parent::present($row),
            'configuration' => $this->redact($row->configuration ?? []),
            'action_id' => $row->action_id,
            'receiver' => $row->action?->name,
            'uuid' => $row->uuid,
            'url' => $row->getUrl(),
        ];
    }

    protected function write(string $operation, ?int $id, array $data): array
    {
        throw new InvalidArgumentException('Use list, get or copy. This tool does not edit or delete existing receivers.');
    }

    protected function copy(array $snapshot, array $overrides): array
    {
        if (! empty($snapshot['configuration']) && ! array_key_exists('configuration', $overrides)) {
            throw new InvalidArgumentException('Provide the complete destination configuration after resolving its company-specific references. No receiver was created.');
        }
        $data = array_replace($snapshot, ['is_active' => false, 'configuration' => []], $overrides);
        if ($this->redact($data['configuration']) !== $data['configuration'] || $this->containsRedaction($data['configuration'])) {
            throw new InvalidArgumentException('Configure credentials through secure administrator setup, not chat. No receiver was created.');
        }
        if ($data['configuration'] !== [] && array_is_list($data['configuration'])) {
            throw new InvalidArgumentException('configuration must be an object of named settings.');
        }
        $action = $this->resolveReceiver((string) $snapshot['receiver']);
        if ($action === null || (int) $action->getId() !== (int) $snapshot['action_id']) {
            throw new InvalidArgumentException('The source handler is not an available receiver in this app.');
        }
        $this->requireName($data);
        $this->assertUniqueName($this->resourceQuery(), $data);
        $created = new CreateReceiverWebhookAction(new ReceiverWebhookData(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            action: WorkflowAction::findOrFail($action->getId()),
            name: $data['name'],
            description: $data['description'],
            configuration: $data['configuration'],
            is_active: (bool) $data['is_active'],
            run_async: (bool) $data['run_async'],
        ))->execute();

        return [
            'success' => true,
            'source_id' => $snapshot['id'],
            'record' => $this->present($created->fresh()),
            'receiver_id_map' => [$snapshot['id'] => $created->getId()],
            'message' => 'New destination endpoint created. External provider configuration was not changed. Treat its URL as private.',
        ];
    }

    private function redact(array $configuration): array
    {
        foreach ($configuration as $key => $value) {
            if (Str::isCredentialKey((string) $key)) {
                $configuration[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $configuration[$key] = $this->redact($value);
            }
        }

        return $configuration;
    }

    private function containsRedaction(array $configuration): bool
    {
        foreach ($configuration as $value) {
            if ($value === '[REDACTED]' || (is_array($value) && $this->containsRedaction($value))) {
                return true;
            }
        }

        return false;
    }
}
