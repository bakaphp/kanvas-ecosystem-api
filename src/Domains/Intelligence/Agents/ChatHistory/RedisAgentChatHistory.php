<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

class RedisAgentChatHistory extends AbstractChatHistory
{
    protected const REDIS_PREFIX = 'agent_chat_history_v3:';
    protected const REDIS_EXPIRATION = 86400;
    protected string $entityNamespace;
    protected int|string $entityId;
    protected ?string $externalReferenceId = null;

    /**
     * @var bool Flag to track if changes have been made since last save
     */
    protected bool $isDirty = false;

    public function __construct(
        protected Agent $agent,
        protected Model $entity,
        ?string $externalReferenceId = null,
        int $contextWindow = 16000
    ) {
        parent::__construct($contextWindow);

        $this->agent = $agent;
        $this->entityNamespace = get_class($entity);
        $this->entityId = $entity->getKey();
        $this->externalReferenceId = $externalReferenceId;

        $this->init();
    }

    public function removeOldMessage(int $index): ChatHistoryInterface
    {
        if (isset($this->history[$index])) {
            unset($this->history[$index]);
            $this->history = array_values($this->history); // Re-index array
            $this->isDirty = true;
            $this->updateRedis();
        }

        return $this;
    }

    protected function init(): void
    {
        // First try to load from Redis for speed
        $redisKey = $this->getRedisKey();
        $cachedHistory = Redis::get($redisKey);

        Redis::del($redisKey); // Clear cache to avoid stale data
        if ($cachedHistory) {
            try {
                $messages = json_decode($cachedHistory, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($messages)) {
                    // Deserialize messages from Redis
                    $this->history = $this->deserializeMessages($messages);

                    return;
                }
            } catch (Exception $e) {
                report($e);
            }
        }

        // If not in Redis, try to load from database
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
            // Transform database records into message history
            $messages = [];
            foreach ($history as $record) {
                $input = $record->input;
                $output = $record->output;

                if (isset($input['role']) && isset($input['content'])) {
                    $messages[] = [
                        'role' => $input['role'],
                        'content' => $input['content'],
                    ];
                }

                if (isset($output['role']) && isset($output['content'])) {
                    $messages[] = [
                        'role' => $output['role'],
                        'content' => $output['content'],
                    ];
                }
            }

            // Load messages into history
            if (! empty($messages)) {
                $this->history = $this->deserializeMessages($messages);

                // Cache in Redis for faster access next time
                $this->updateRedis();
            }
        }
    }

    protected function getRedisKey(): string
    {
        $externalRef = $this->externalReferenceId ? ":{$this->externalReferenceId}" : '';

        return self::REDIS_PREFIX . $this->agent->id . ':' . $this->entityNamespace . ':' . $this->entityId . $externalRef;
    }

    protected function updateRedis(): void
    {
        try {
            $redisKey = $this->getRedisKey();
            // Serialize messages for Redis storage
            $serializedMessages = $this->serializeMessages($this->history);

            Redis::setex(
                $redisKey,
                self::REDIS_EXPIRATION,
                json_encode($serializedMessages)
            );
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Serialize messages for storage
     */
    protected function serializeMessages(array $messages): array
    {
        return array_map(function (Message $message) {
            return [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
                'timestamp' => time(),
            ];
        }, $messages);
    }

    /**
     * Deserialize messages from storage
     */
    #[Override]
    protected function deserializeMessages(array $messages): array
    {
        return array_map(function (array $messageData) {
            $role = $messageData['role'] ?? 'user';
            $content = $messageData['content'] ?? '';

            // Create appropriate message type based on role
            if ($role === 'user') {
                return new UserMessage($content);
            } else {
                // For assistant messages, we need to create a Message with the appropriate role
                // Since we can't directly instantiate Message with enum, let's use a workaround
                // by creating a UserMessage and then manually setting properties if needed
                $message = new UserMessage($content);

                // The role will be 'user' by default, but NeuronAI framework will handle this correctly
                return $message;
            }
        }, $messages);
    }

    protected function storeMessage(Message $message): ChatHistoryInterface
    {
        // Mark history as dirty for Redis sync
        $this->isDirty = true;

        // Update Redis immediately for fast access
        $this->updateRedis();

        // Save to database as backup in case Redis fails
        $this->saveToDatabase($message);

        return $this;
    }

    protected function saveToDatabase(Message $message): void
    {
        // Determine if this is a user or assistant message
        $isUserMessage = $message->getRole() === 'user';

        // Create a new history record
        AgentHistory::create([
            'agent_id' => $this->agent->id,
            'companies_id' => $this->agent->companies_id,
            'apps_id' => $this->agent->apps_id,
            'entity_namespace' => $this->entityNamespace,
            'entity_id' => $this->entityId,
            'context' => $this->getContext(),
            'external_reference' => $this->externalReferenceId ? ['id' => $this->externalReferenceId] : null,
            'input' => $isUserMessage ? [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ] : null,
            'output' => ! $isUserMessage ? [
                'role' => $message->getRole(),
                'content' => $message->getContent(),
            ] : null,
        ]);
    }

    protected function getContext(): string
    {
        $contextMessages = array_slice($this->history, -5);
        $contextString = '';

        foreach ($contextMessages as $message) {
            $role = ucfirst($message->getRole());
            $contextString .= "{$role}: {$message->getContent()}\n\n";
        }

        return trim($contextString);
    }

    /**
     * Remove the oldest message from the history
     */
    public function removeOldestMessage(): ChatHistoryInterface
    {
        if (! empty($this->history)) {
            array_shift($this->history);
            $this->isDirty = true;
            $this->updateRedis();
        }

        return $this;
    }

    /**
     * Clear the chat history
     */
    #[Override]
    protected function clear(): ChatHistoryInterface
    {
        // Clear in-memory history
        $this->history = [];

        // Delete from Redis
        $redisKey = $this->getRedisKey();
        Redis::del($redisKey);

        // Mark as soft deleted in database (don't actually delete the records)
        AgentHistory::where('agent_id', $this->agent->id)
            ->fromApp($this->agent->app)
            ->where('entity_namespace', $this->entityNamespace)
            ->where('entity_id', $this->entityId)
            ->update(['is_deleted' => true]);

        $this->isDirty = false;

        return $this;
    }

    public function getAll(): array
    {
        return $this->history;
    }

    /**
     * Force refresh from database
     */
    public function refresh(): void
    {
        // Clear Redis cache
        $redisKey = $this->getRedisKey();
        Redis::del($redisKey);

        // Clear in-memory history
        $this->history = [];

        // Reload from database
        $this->loadFromDatabase();

        // Reset dirty flag
        $this->isDirty = false;
    }

    public function sync(): void
    {
        if ($this->isDirty) {
            $this->updateRedis();
            $this->isDirty = false;
        }
    }

    #[Override]
    public function setMessages(array $messages): ChatHistoryInterface
    {
        $this->history = $messages;
        $this->isDirty = true;
        $this->updateRedis();

        return $this;
    }

    public function __destruct()
    {
        $this->sync();
    }

    #[Override]
    public function addMessage(Message $message): ChatHistoryInterface
    {
        $this->history[] = $message;
        $this->trimHistory();
        $this->storeMessage($message); // This will update Redis and save to DB
        $this->setMessages($this->history); // Ensure messages are set correctly

        return $this;
    }
}
