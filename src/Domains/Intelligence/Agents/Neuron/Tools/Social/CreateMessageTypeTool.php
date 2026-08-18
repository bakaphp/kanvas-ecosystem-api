<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Declare a kind of record before wiring automation to it — the deliberate half of what
 * `create_message` would otherwise do as a side effect of writing the first one.
 *
 * Not admin-guarded, deliberately. `create_message` already creates a type on demand for any verb it
 * has not seen, so gating the explicit path while the implicit one stays open would be theatre. What
 * makes a type consequential is the *rule* pointed at it, and creating rules is admin-guarded.
 */
#[AgentTool(name: 'Create Message Type', category: 'social')]
class CreateMessageTypeTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_message_type',
            description: 'Create a kind of record this app can store — "news-article", "weekly-report" — so '
                . 'a workflow can be pointed at it and agents can write records of it. Call '
                . 'list_message_types first: if one already exists for this purpose, use that instead of '
                . 'making a near-duplicate, because a workflow watches one exact type and a second one '
                . 'sitting beside it is the usual reason records go nowhere.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'verb',
                type: PropertyType::STRING,
                description: 'The identifier, lower-case and hyphenated, e.g. "news-article". This is what '
                    . 'create_message takes and what a workflow is configured against.',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Optional human-readable name, e.g. "News Article". Derived from the verb when '
                    . 'omitted.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $verb, ?string $name = null): array
    {
        if (! isset($this->app)) {
            return [
                'status' => 'error',
                'message' => 'This tool has no app context, so it cannot create a message type.',
            ];
        }

        $verb = mb_strtolower(trim($verb));

        if ($verb === '') {
            return [
                'status' => 'error',
                'message' => 'The verb is empty. Give it something like "news-article".',
            ];
        }

        $existing = MessageType::query()
            ->where('apps_id', $this->app->getId())
            ->whereRaw('LOWER(verb) = ?', [$verb])
            ->first();

        if ($existing !== null) {
            return [
                'status' => 'success',
                'created' => false,
                'verb' => $existing->verb,
                'name' => $existing->name,
                'message_type_id' => $existing->getId(),
                'note' => 'This type already existed; nothing was changed. Use this verb in create_message.',
            ];
        }

        try {
            $type = MessageTypeService::getOrCreate(
                app: $this->app,
                verb: $verb,
                name: $name !== null && trim($name) !== '' ? trim($name) : null,
            );
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The message type could not be created: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'created' => true,
            'verb' => $type->verb,
            'name' => $type->name,
            'message_type_id' => $type->getId(),
            'note' => 'Nothing runs on this type yet. Point a workflow at message_type_id '
                . $type->getId() . ' for records of it to do anything.',
        ];
    }
}
