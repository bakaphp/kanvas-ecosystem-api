<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use Kanvas\Filesystem\Models\Filesystem;

/**
 * Folds a message's attachments into the prompt text, in the shape the agent behind it can act on.
 *
 * Two shapes, because the two runtimes can act on different things:
 *
 * - `withAttachments()` (URLs) — the container runtimes (Hermes /v1/chat/completions, OpenClaw
 *   /v1/responses) reject every non-image content upload with `400 unsupported_content_type`, so
 *   the only way to surface a PDF/doc/audio/video is to put its URL in the prompt and let the agent
 *   fetch it with whatever tool (`curl`, web skill, ...) its container has.
 * - `withFilesystemMarkers()` (filesystem ids) — an in-process Neuron agent has Kanvas tools instead
 *   (`extract_invoice_data`, `read_file`, `get_file_link`), and every one of them keys on a
 *   filesystem_id, not a URL. A URL alone leaves an AP/AR agent unable to read the invoice a person
 *   just sent it.
 *
 * Single source of truth for both, so the prompt an agent sees is identical regardless of which
 * surface the file arrived on — email (Mailgun), Slack, or the in-app chat.
 */
class AttachmentPromptBuilder
{
    /**
     * @param list<string> $fileUrls
     */
    public static function withAttachments(string $message, array $fileUrls): string
    {
        if ($fileUrls === []) {
            return $message;
        }

        return self::append($message, 'Attached files:' . "\n" . implode(
            "\n",
            array_map(static fn (string $url): string => '- ' . $url, $fileUrls),
        ));
    }

    /**
     * Bytes deliberately stay out of the prompt (token cost) — the marker is what grounds the reply,
     * and without it the model reuses an older attachment from its own chat history.
     *
     * @param iterable<Filesystem> $files
     */
    public static function withFilesystemMarkers(string $message, iterable $files): string
    {
        $markers = [];

        foreach ($files as $file) {
            $markers[] = "[Attached file on this message — filesystem_id: {$file->getId()}, filename: \"{$file->name}\"]";
        }

        if ($markers === []) {
            return $message;
        }

        return self::append($message, implode("\n", $markers));
    }

    private static function append(string $message, string $block): string
    {
        return $message === '' ? $block : $message . "\n\n" . $block;
    }
}
