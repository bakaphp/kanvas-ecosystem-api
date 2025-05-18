<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum MessageTypeEnum: string
{
    case TEXT = 'whatsapp-text';
    case IMAGE = 'whatsapp-image';
    case VIDEO = 'whatsapp-video';
    case DOCUMENT = 'whatsapp-document';
    case AUDIO = 'whatsapp-audio';
    case STICKER = 'whatsapp-sticker';
    case CONTACT = 'whatsapp-contact';
    case LOCATION = 'whatsapp-location';
    case UNKNOWN = 'whatsapp-unknown';

    public static function getMessageType(array $messageContent): self
    {
        return match (true) {
            isset($messageContent['conversation']) => self::TEXT,
            isset($messageContent['imageMessage']) => self::IMAGE,
            isset($messageContent['videoMessage']) => self::VIDEO,
            isset($messageContent['documentMessage']) => self::DOCUMENT,
            isset($messageContent['audioMessage']) => self::AUDIO,
            isset($messageContent['stickerMessage']) => self::STICKER,
            isset($messageContent['contactMessage']) => self::CONTACT,
            isset($messageContent['locationMessage']) => self::LOCATION,
            default => self::UNKNOWN,
        };
    }

    public static function isDocumentType(string $type): bool
    {
        return in_array($type, [
            self::DOCUMENT->value,
            self::AUDIO->value,
            self::IMAGE->value,
            self::VIDEO->value,
        ]);
    }
}
