<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Create Entity Channel', category: 'social')]
class CreateEntityChannelTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
    ) {
        parent::__construct(
            name: 'create_entity_channel',
            description: 'Create or retrieve a Social channel attached to an existing entity in the current company. '
                . 'Use only registered entity namespaces. Returns the channel_id needed by add_message_to_channel.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'entity_namespace',
                type: PropertyType::STRING,
                description: 'Registered model namespace for the entity, for example Kanvas\\Guild\\Leads\\Models\\Lead.',
                required: true,
            ),
            new ToolProperty(
                name: 'entity_id',
                type: PropertyType::STRING,
                description: 'ID or UUID of the existing entity in the current app and company.',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Human-readable channel name.',
                required: true,
            ),
            new ToolProperty(
                name: 'slug',
                type: PropertyType::STRING,
                description: 'Optional stable channel slug. Omit to derive it from the name.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional channel description.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $entity_namespace,
        string $entity_id,
        string $name,
        ?string $slug = null,
        ?string $description = null,
    ): array {
        $entityNamespace = trim($entity_namespace);
        $entityId = trim($entity_id);
        $name = trim($name);

        if ($entityNamespace === '' || $entityId === '' || $name === '') {
            return [
                'status' => 'error',
                'message' => 'entity_namespace, entity_id, and name are required.',
            ];
        }

        try {
            $systemModule = SystemModulesRepository::getByUuidOrModelName($entityNamespace, $this->app);
            $modelClass = SystemModules::convertLegacySystemModules($systemModule->model_name);
            $entity = $this->resolveEntity($modelClass, $entityId);

            if ($entity === null) {
                return [
                    'status' => 'error',
                    'message' => 'The entity does not exist in the current app and company.',
                ];
            }

            $channel = new CreateChannelAction(new ChannelData(
                apps: $this->app,
                companies: $this->company,
                users: $this->user,
                entity_id: $entity->getKey(),
                entity_namespace: $modelClass,
                name: $name,
                description: trim((string) $description),
                slug: Str::trimToNull((string) $slug),
            ))->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The entity channel could not be created. Verify the registered namespace and entity.',
            ];
        }

        return [
            'status' => 'success',
            'channel_id' => $channel->getId(),
            'channel_slug' => $channel->slug,
            'entity_namespace' => $channel->entity_namespace,
            'entity_id' => (string) $channel->entity_id,
        ];
    }

    private function resolveEntity(string $modelClass, string $entityId): ?Model
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $model = new $modelClass();
        $field = method_exists($model, 'hasColumn') && $model->hasColumn('uuid') && str_contains($entityId, '-')
            ? 'uuid'
            : $model->getKeyName();
        $query = $modelClass::query()->where($field, $entityId);

        if (method_exists($model, 'hasColumn') && $model->hasColumn('apps_id')) {
            $query->where('apps_id', $this->app->getId());
        }

        if (method_exists($model, 'hasColumn') && $model->hasColumn('companies_id')) {
            $query->where('companies_id', $this->company->getId());
        }

        if (method_exists($model, 'hasColumn') && $model->hasColumn('is_deleted')) {
            $query->where('is_deleted', 0);
        }

        return $query->first();
    }
}
