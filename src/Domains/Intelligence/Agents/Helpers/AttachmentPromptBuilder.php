<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

/**
 * Folds non-image attachment URLs into a chat message under an "Attached files:" list.
 *
 * The runtime chat APIs (Hermes /v1/chat/completions, OpenClaw /v1/responses) reject every
 * non-image content upload with `400 unsupported_content_type`, so the only way to surface
 * a PDF/doc/audio/video attachment is to put its URL into the prompt and let the agent
 * fetch it with whatever tool (`curl`, web skill, ...) is available in its container.
 *
 * Single source of truth used by both `aiAgentUserChat`/`aiAgentChat` (client passes URLs
 * explicitly) and `RuntimeAgentChannelResponderAction` (URLs come from the inbound message's
 * Filesystem attachments) so the prompt shape an agent sees is identical regardless of how
 * the chat was triggered.
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

        $attachmentList = "Attached files:\n" . implode(
            "\n",
            array_map(static fn (string $url): string => '- ' . $url, $fileUrls),
        );

        return $message === '' ? $attachmentList : $message . "\n\n" . $attachmentList;
    }
}
