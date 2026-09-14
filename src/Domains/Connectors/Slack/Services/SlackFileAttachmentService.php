<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Kanvas\Connectors\Slack\Actions\DownloadMessageFileAction;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

class SlackFileAttachmentService
{
    /**
     * Slack's url_private needs the bot token to fetch, so we download-with-auth and re-upload
     * rather than store the raw URL. One bad file must not sink the message.
     */
    public static function attachAll(Message $message, Client $client, mixed $files): void
    {
        if (! is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (! is_array($file) || ! self::isDownloadable($file)) {
                continue;
            }

            try {
                new DownloadMessageFileAction($message, $client, $file)->execute();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Slack sends a stub object with no url_private for files the app cannot read directly (Slack
     * Connect, file-access limits). An ordinary outcome, so it is skipped rather than reported —
     * throwing filed one Sentry event per message carrying one (KANVAS-ECOSYSTEM-693).
     */
    private static function isDownloadable(array $file): bool
    {
        return trim((string) ($file['url_private_download'] ?? $file['url_private'] ?? '')) !== '';
    }
}
