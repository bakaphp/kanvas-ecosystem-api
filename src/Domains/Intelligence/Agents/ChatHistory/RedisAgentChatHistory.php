<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;

class RedisAgentChatHistory extends AbstractChatHistory
{
    protected const REDIS_PREFIX = 'agent_chat_history:';
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
        int $contextWindow = 500000000000
    ) {
        parent::__construct($contextWindow);

        $this->agent = $agent;
        $this->entityNamespace = get_class($entity);
        $this->entityId = $entity->getKey();
        $this->externalReferenceId = $externalReferenceId;

        $this->init();
    }

    protected function init(): void
    {
        // First try to load from Redis for speed
        $redisKey = $this->getRedisKey();
        //Redis::del($redisKey);
        $cachedHistory = Redis::get($redisKey);

        /*  if ($cachedHistory) {
             $messages = json_decode($cachedHistory, true);
             $this->history = $this->unserializeMessages($messages);

             return;
         } */

        // If not in Redis, try to load from database
        $history = AgentHistory::where('agent_id', $this->agent->id)
            ->fromApp($this->agent->app)
            ->where('entity_namespace', $this->entityNamespace)
            ->where('entity_id', $this->entityId)
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

            $this->history = $this->unserializeMessages($messages);

            // Cache in Redis for faster access next time
            $this->updateRedis();
        }
    }

    protected function getRedisKey(): string
    {
        return self::REDIS_PREFIX . $this->agent->id . ':' . $this->entityNamespace . ':' . $this->entityId;
    }

    protected function updateRedis(): void
    {
        $redisKey = $this->getRedisKey();
        Redis::setex(
            $redisKey,
            self::REDIS_EXPIRATION,
            json_encode($this->jsonSerialize())
        );
    }

    protected function storeMessage(Message $message): ChatHistoryInterface
    {
        // Mark history as dirty so we know to save to database
        $this->isDirty = true;

        // Update Redis immediately for fast access
        $this->updateRedis();

        // Save to database asynchronously
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

        return $contextString;
    }

    /**
     * Remove the oldest message from the history
     */
    public function removeOldestMessage(): ChatHistoryInterface
    {
        // Mark history as dirty
        $this->isDirty = true;

        // Update Redis
        $this->updateRedis();

        // No need to modify database as we're just removing from context window

        return $this;
    }

    /**
     * Clear the chat history
     */
    protected function clear(): ChatHistoryInterface
    {
        // Delete from Redis
        $redisKey = $this->getRedisKey();
        Redis::del($redisKey);

        // Mark as soft deleted in database (don't actually delete the records)
        AgentHistory::where('agent_id', $this->agent->id)
            ->fromApp($this->agent->app)
            ->where('entity_namespace', $this->entityNamespace)
            ->where('entity_id', $this->entityId)
            ->update(['is_deleted' => true]);

        return $this;
    }

    public function getAll(): array
    {
        return $this->history;
    }

    public function sync(): void
    {
        if ($this->isDirty) {
            $this->updateRedis();
            $this->isDirty = false;
        }
    }

    public function __destruct()
    {
        $this->sync();
    }
}
