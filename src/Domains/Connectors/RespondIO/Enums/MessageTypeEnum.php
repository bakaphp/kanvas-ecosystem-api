<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Enums;

enum MessageTypeEnum: string
{
    case TEXT = 'respondio-text';
    case IMAGE = 'respondio-image';
    case VIDEO = 'respondio-video';
    case AUDIO = 'respondio-audio';
    case FILE = 'respondio-file';
    case LOCATION = 'respondio-location';
    case QUICK_REPLY = 'respondio-quick-reply';
    case UNKNOWN = 'respondio-unknown';

    public static function getMessageType(array $messageContent): self
    {
        $type = $messageContent['type'] ?? 'unknown';

        return match ($type) {
            'text' => self::TEXT,
            'image' => self::IMAGE,
            'video' => self::VIDEO,
            'audio' => self::AUDIO,
            'file' => self::FILE,
            'location' => self::LOCATION,
            'quick_reply' => self::QUICK_REPLY,
            default => self::UNKNOWN,
        };
    }

    public static function isDocumentType(string $type): bool
    {
        return in_array($type, [
            self::FILE->value,
            self::AUDIO->value,
            self::IMAGE->value,
            self::VIDEO->value,
        ]);
    }
}
