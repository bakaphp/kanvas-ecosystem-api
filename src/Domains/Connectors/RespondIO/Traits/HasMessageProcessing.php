<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Traits;

use Baka\Support\Str;
use Kanvas\Connectors\RespondIO\Enums\MessageTypeEnum;

trait HasMessageProcessing
{
    protected function createMessageSlug(string $messageId, string $identifier): string
    {
        return 'respondio-' . Str::slug($messageId . '-' . $identifier);
    }

    protected function extractMessageText(array $messageData, MessageTypeEnum $messageType): ?string
    {
        $messageContent = $messageData['message'] ?? $messageData;

        return match ($messageType) {
            MessageTypeEnum::TEXT => $messageContent['text'] ?? null,
            MessageTypeEnum::IMAGE,
            MessageTypeEnum::VIDEO => $messageContent['caption'] ?? $messageContent['attachment']['description'] ?? null,
            MessageTypeEnum::FILE,
            MessageTypeEnum::AUDIO => $messageContent['attachment']['fileName'] ?? $messageContent['caption'] ?? null,
            MessageTypeEnum::LOCATION => $messageContent['address'] ?? null,
            MessageTypeEnum::QUICK_REPLY => $messageContent['title'] ?? null,
            default => $messageContent['text'] ?? null,
        };
    }
}
