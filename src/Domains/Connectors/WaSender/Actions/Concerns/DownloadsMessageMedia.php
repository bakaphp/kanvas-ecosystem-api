<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions\Concerns;

use Kanvas\Connectors\WaSender\Actions\DownloadMessageFileAction;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * The message is already filed when its media is fetched, so a download that fails marks the
 * message and lets the rest of the turn carry on. Shared by the three filing paths.
 */
trait DownloadsMessageMedia
{
    protected function downloadMedia(Message $message): void
    {
        try {
            new DownloadMessageFileAction($message)->execute();
        } catch (Throwable $e) {
            $this->recordMediaFailure($message, $e);
        }
    }

    /**
     * A refusal is the provider answering, not faulting: a 39MB clip exceeds WaSender's 25MB decrypt
     * limit and no retry changes that, so reporting it only floods Sentry (KANVAS-ECOSYSTEM-68N).
     * A missing api key, a network fault or storage still is a fault, and still reports.
     */
    private function recordMediaFailure(Message $message, Throwable $e): void
    {
        $message->addTag('media-not-downloaded');
        $message->set('media_download_error', mb_substr($e->getMessage(), 0, 500));

        if (! $e instanceof WaSenderRefusedException) {
            report($e);
        }
    }
}
