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

    /**
     * Envelopes WhatsApp wraps the real content in. Each one nests the payload under `message`.
     */
    public const WRAPPER_KEYS = [
        'viewOnceMessage',
        'viewOnceMessageV2',
        'viewOnceMessageV2Extension',
        'ephemeralMessage',
        'documentWithCaptionMessage',
        'editedMessage',
        'deviceSentMessage',
    ];

    /**
     * Siblings of the content node that carry protocol metadata, never content. Group payloads put
     * these first often enough that positional lookup (`array_key_first`) picks the wrong node.
     */
    public const METADATA_KEYS = [
        'messageContextInfo',
        'senderKeyDistributionMessage',
        'protocolMessage',
    ];

    /**
     * Content nodes that carry a `mediaKey` + `url` pair we can download.
     */
    public const MEDIA_KEYS = [
        'imageMessage',
        'videoMessage',
        'audioMessage',
        'documentMessage',
        'stickerMessage',
    ];

    /**
     * Peel every envelope until the real content node is exposed. Depth-bounded: a malformed
     * payload that nests wrappers into itself must not spin.
     */
    public static function unwrap(array $messageContent): array
    {
        for ($depth = 0; $depth < 5; $depth++) {
            $wrapper = null;

            foreach (self::WRAPPER_KEYS as $key) {
                if (isset($messageContent[$key]['message'])) {
                    $wrapper = $key;

                    break;
                }
            }

            if ($wrapper === null) {
                return $messageContent;
            }

            $messageContent = $messageContent[$wrapper]['message'];
        }

        return $messageContent;
    }

    /**
     * The key holding the actual content, metadata siblings skipped.
     */
    public static function contentKey(array $messageContent): ?string
    {
        foreach (array_keys(self::unwrap($messageContent)) as $key) {
            if (! in_array($key, self::METADATA_KEYS, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The key holding downloadable media, or null when the message carries none.
     */
    /**
     * The unwrapped node the media actually hangs off, or null when the payload carries none.
     *
     * Callers want the node, not the key — resolving it here keeps them from unwrapping a second
     * time just to index into the result.
     *
     * @return array<string, mixed>|null
     */
    public static function mediaNode(array $messageContent): ?array
    {
        $content = self::unwrap($messageContent);

        foreach (self::MEDIA_KEYS as $key) {
            if (isset($content[$key])) {
                return (array) $content[$key];
            }
        }

        return null;
    }

    public static function mediaKey(array $messageContent): ?string
    {
        $content = self::unwrap($messageContent);

        foreach (self::MEDIA_KEYS as $key) {
            if (isset($content[$key])) {
                return $key;
            }
        }

        return null;
    }

    public static function getMessageType(array $messageContent): self
    {
        $content = self::unwrap($messageContent);

        return match (true) {
            isset($content['conversation']), isset($content['extendedTextMessage']) => self::TEXT,
            isset($content['imageMessage']) => self::IMAGE,
            isset($content['videoMessage']) => self::VIDEO,
            isset($content['documentMessage']) => self::DOCUMENT,
            isset($content['audioMessage']) => self::AUDIO,
            isset($content['stickerMessage']) => self::STICKER,
            isset($content['contactMessage']) => self::CONTACT,
            isset($content['locationMessage']) => self::LOCATION,
            default => self::UNKNOWN,
        };
    }

    /**
     * Text carried by the message, or null when it has none. A forwarded ad card arrives as an
     * `extendedTextMessage` with only `mediaKey` + `contextInfo` and no `text` at all.
     */
    public static function extractText(array $messageContent): ?string
    {
        $content = self::unwrap($messageContent);

        $text = $content['conversation']
            ?? $content['extendedTextMessage']['text']
            ?? $content['imageMessage']['caption']
            ?? $content['videoMessage']['caption']
            ?? $content['documentMessage']['caption']
            ?? $content['contactMessage']['displayName']
            ?? $content['locationMessage']['name']
            ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
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
