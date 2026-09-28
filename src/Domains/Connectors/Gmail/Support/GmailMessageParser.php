<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Support;

use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;

/** Walks a Gmail MessagePart tree (multipart messages nest recursively) to pull out headers, body text, and attachment refs. */
class GmailMessageParser
{
    /**
     * @param array<int, MessagePartHeader> $headers
     */
    public static function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (strcasecmp((string) $header->getName(), $name) === 0) {
                return $header->getValue();
            }
        }

        return null;
    }

    /**
     * @return array{plain: ?string, html: ?string}
     */
    public static function extractBody(MessagePart $part): array
    {
        $plain = null;
        $html = null;

        self::walkParts($part, function (MessagePart $node) use (&$plain, &$html): void {
            $data = $node->getBody()?->getData();

            if (empty($data)) {
                return;
            }

            match ($node->getMimeType()) {
                'text/plain' => $plain ??= self::decodeBase64Url($data),
                'text/html' => $html ??= self::decodeBase64Url($data),
                default => null,
            };
        });

        return ['plain' => $plain, 'html' => $html];
    }

    /**
     * @return array<int, array{attachment_id: string, filename: string, mime_type: string}>
     */
    public static function extractAttachments(MessagePart $part): array
    {
        $attachments = [];

        self::walkParts($part, function (MessagePart $node) use (&$attachments): void {
            $filename = $node->getFilename();
            $attachmentId = $node->getBody()?->getAttachmentId();

            if (! empty($filename) && ! empty($attachmentId)) {
                $attachments[] = [
                    'attachment_id' => $attachmentId,
                    'filename' => $filename,
                    'mime_type' => (string) $node->getMimeType(),
                ];
            }
        });

        return $attachments;
    }

    public static function decodeBase64Url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    private static function walkParts(MessagePart $part, callable $visitor): void
    {
        $visitor($part);

        foreach ($part->getParts() ?? [] as $child) {
            self::walkParts($child, $visitor);
        }
    }
}
