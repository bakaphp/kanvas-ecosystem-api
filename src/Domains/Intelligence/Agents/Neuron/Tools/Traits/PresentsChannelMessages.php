<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Support\Str;
use Kanvas\Social\Messages\Models\Message;

/**
 * One shape for a channel message across the reading tools (window, search, thread), so an id the
 * model takes from one of them means the same thing in the next.
 */
trait PresentsChannelMessages
{
    public const int PREVIEW_CHARS = 800;

    /**
     * Omitted, 0 and oversized limits all collapse to a real page size, so a model varying a no-op
     * argument cannot turn one read into several.
     */
    protected function clampLimit(?int $limit, int $default, int $max): int
    {
        return min(max((int) $limit ?: $default, 1), $max);
    }

    /**
     * @return array{status: string, reason: string, message: string}
     */
    protected function channelToolWithoutTenant(string $subject): array
    {
        return ['status' => 'error', ...$this->tenantContextMissingError($subject)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeChannelMessage(Message $message): array
    {
        $text = trim($message->contentText());

        return [
            'message_id' => $message->getId(),
            'parent_id' => (int) $message->parent_id > 0 ? (int) $message->parent_id : null,
            'author' => $message->user?->displayname ?? 'unknown',
            'author_user_id' => $message->users_id,
            'created_at' => (string) $message->created_at,
            'text' => Str::limit($text, self::PREVIEW_CHARS),
            'truncated' => mb_strlen($text) > self::PREVIEW_CHARS,
        ];
    }
}
