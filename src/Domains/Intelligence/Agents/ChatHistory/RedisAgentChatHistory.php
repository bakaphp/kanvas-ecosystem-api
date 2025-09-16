<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use Override;

class RedisAgentChatHistory extends AbstractChatHistory
{
    protected string $entityNamespace;
    protected int|string $entityId;
    protected ?string $externalReferenceId = null;

    public function __construct(
        protected Agent $agent,
        protected Model $entity,
        ?string $externalReferenceId = null,
        int $contextWindow = 16000
    ) {
        $this->agent = $agent;
        $this->entityNamespace = get_class($entity);
        $this->entityId = $entity->getKey();
        $this->externalReferenceId = $externalReferenceId;

        parent::__construct($contextWindow);
        $this->loadFromDatabase();
    }

    protected function loadFromDatabase(): void
    {
        $history = AgentHistory::where('agent_id', $this->agent->id)
            ->fromApp($this->agent->app)
            ->where('entity_namespace', $this->entityNamespace)
            ->where('entity_id', $this->entityId)
            ->where('is_deleted', 0)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($history->isNotEmpty()) {
            $messages = [];

            foreach ($history as $record) {
                // Add input message (user message)
                if ($record->input && isset($record->input['role']) && isset($record->input['content'])) {
                    $messages[] = [
                        'role' => $record->input['role'],
                        'content' => $record->input['content'],
                    ];
                }

                // Add output message (assistant message)
                if ($record->output && isset($record->output['role']) && isset($record->output['content'])) {
                    $messages[] = [
                        'role' => $record->output['role'],
                        'content' => $record->output['content'],
                    ];
                }
            }

            if (! empty($messages)) {
                $this->history = $this->deserializeMessages($messages);
            }
        }
    }

    /**
     * Deserialize messages from storage using the proper NeuronAI method
     */
    #[Override]
    protected function deserializeMessages(array $messages): array
    {
        return array_map(function (array $messageData) {
            return $this->deserializeMessage($messageData);
        }, $messages);
    }

    /**
     * Store message to database
     */
    protected function storeMessage(Message $message): ChatHistoryInterface
    {
        $this->saveToDatabase($message);

        return $this;
    }

    protected function saveToDatabase(Message $message): void
    {
        try {
            $isUserMessage = $message->getRole() === MessageRole::USER;

            AgentHistory::create([
                'agent_id' => $this->agent->id,
                'companies_id' => $this->agent->companies_id,
                'apps_id' => $this->agent->apps_id,
                'entity_namespace' => $this->entityNamespace,
                'entity_id' => $this->entityId,
                'context' => $this->getContext(),
                'external_reference' => $this->externalReferenceId ? ['id' => $this->externalReferenceId] : null,
                'input' => $isUserMessage ? $message->jsonSerialize() : null,
                'output' => ! $isUserMessage ? $message->jsonSerialize() : null,
            ]);
        } catch (Exception $e) {
            report($e);

            throw $e;
        }
    }

    protected function getContext(): string
    {
        $contextMessages = array_slice($this->history, -5);
        $contextString = '';

        foreach ($contextMessages as $message) {
            $role = $message->getRole()->value ?? $message->getRole();
            $role = ucfirst($role);
            $contextString .= "{$role}: {$message->getContent()}\n\n";
        }

        return trim($contextString);
    }

    /**
     * Remove message by index
     */
    public function removeOldMessage(int $index): ChatHistoryInterface
    {
        if (isset($this->history[$index])) {
            unset($this->history[$index]);
            $this->history = array_values($this->history); // Re-index array
        }

        return $this;
    }

    /**
     * Remove the oldest message from the history
     */
    public function removeOldestMessage(): ChatHistoryInterface
    {
        if (! empty($this->history)) {
            array_shift($this->history);
        }

        return $this;
    }

    /**
     * Clear the chat history (required by AbstractChatHistory)
     */
    #[Override]
    protected function clear(): ChatHistoryInterface
    {
        // Clear in-memory history
        $this->history = [];

        // Mark as soft deleted in database
        AgentHistory::where('agent_id', $this->agent->id)
            ->fromApp($this->agent->app)
            ->where('entity_namespace', $this->entityNamespace)
            ->where('entity_id', $this->entityId)
            ->update(['is_deleted' => true]);

        return $this;
    }

    /**
     * Clear the chat history (public interface)
     */
    #[Override]
    public function flushAll(): ChatHistoryInterface
    {
        return $this->clear();
    }

    /**
     * Get all messages
     */
    public function getAll(): array
    {
        return $this->history;
    }

    /**
     * Force refresh from database
     */
    public function refresh(): void
    {
        // Clear in-memory history
        $this->history = [];

        // Reload from database
        $this->loadFromDatabase();
    }

    /**
     * Set messages and persist to database
     */
    #[Override]
    public function setMessages(array $messages): ChatHistoryInterface
    {
        $this->history = $messages;

        // Store each message that's not already in the database
        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $this->storeMessage($message);
            }
        }

        return $this;
    }

    /**
     * Add a new message
     */
    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->history[] = $message;
        $this->trimHistory();
        $this->storeMessage($message);

        return $this;
    }
}
