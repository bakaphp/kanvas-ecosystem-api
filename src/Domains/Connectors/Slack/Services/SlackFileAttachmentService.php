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
            if (! is_array($file)) {
                continue;
            }

            try {
                new DownloadMessageFileAction($message, $client, $file)->execute();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
