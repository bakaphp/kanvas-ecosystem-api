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

        // Respond.io wraps a file as `attachment` and names the kind one level down.
        if ($type === 'attachment') {
            $type = $messageContent['attachment']['type'] ?? 'file';
        }

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

    public function isDocumentType(): bool
    {
        return in_array($this, [self::FILE, self::AUDIO, self::IMAGE, self::VIDEO], true);
    }
}
