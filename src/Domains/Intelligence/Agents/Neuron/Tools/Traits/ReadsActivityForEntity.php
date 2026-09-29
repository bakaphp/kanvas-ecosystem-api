<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Shared read side of the add_*_note tools: the lead, person and organization activity tools differ
 * only in how they resolve their entity. Reads the same `app_module_message` link the CRM's Activity
 * tab queries, so notes, logged calls, emails and SMS all come back in one feed.
 *
 * Internal notes are in that feed, so a customer-facing agent is refused: a prospect who talks it
 * into another id would read the team's notes on someone else.
 *
 * Requires HasKanvasContext on the host.
 */
trait ReadsActivityForEntity
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;
    private const int MAX_CONTENT_CHARS = 1500;

    /**
     * @return array<int, ToolProperty>
     */
    protected function paginationProperties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of entries to return. Default 25, capped at 100.',
                required: false,
            ),
            new ToolProperty(
                name: 'before_id',
                type: PropertyType::INTEGER,
                description: 'Only entries older than this activity id — pass the last id of a previous page to read further back.',
                required: false,
            ),
        ];
    }

    /**
     * @return array{status: string, message: string}|null
     */
    protected function customerSurfaceRefusal(): ?array
    {
        if (! $this->contextAgent()?->conversesWithCustomer()) {
            return null;
        }

        return [
            'status' => 'error',
            'message' => 'Activity threads hold internal team notes and are not available in a customer conversation.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readActivity(
        BaseModel $entity,
        string $idKey,
        string $name,
        ?int $limit,
        ?int $beforeId,
    ): array {
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $rows = AppModuleMessage::query()
            ->where('system_modules', $entity::class)
            ->where('entity_id', $entity->getId())
            ->where('apps_id', $entity->apps_id)
            ->where('companies_id', $entity->companies_id)
            ->where('is_deleted', 0)
            ->whereHas('message', fn ($q) => $q->where('is_deleted', 0))
            ->when($beforeId !== null, fn ($q) => $q->where('message_id', '<', $beforeId))
            ->with(['message.messageType', 'message.user'])
            ->orderByDesc('message_id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        $activity = $rows
            ->take($limit)
            ->map(fn (AppModuleMessage $row): array => $this->presentActivity($row->message))
            ->values()
            ->all();

        return [
            'status' => 'success',
            $idKey => $entity->getId(),
            'name' => $name,
            'count' => count($activity),
            'has_more' => $hasMore,
            'activity' => $activity,
            'message' => $activity === []
                ? 'This record has no activity yet.'
                : ($hasMore ? 'Older entries exist — call again with before_id set to the last id to read them.' : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentActivity(Message $message): array
    {
        $sender = MessageSenderTypeEnum::fromPayload($message->getMessage());

        return [
            'id' => $message->getId(),
            'created_at' => $message->created_at?->toIso8601String(),
            'type' => $message->messageType?->verb,
            'sender' => $sender?->value,
            'author' => $sender === MessageSenderTypeEnum::CONTACT ? null : $message->user?->displayname,
            'is_internal' => ! $message->isPublic(),
            'content' => Str::limit(trim($message->contentText()), self::MAX_CONTENT_CHARS),
        ];
    }
}
