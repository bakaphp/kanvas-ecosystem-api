<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * Queues the image/audio/PDF description backfill onto a channel-inbound Social message so the
 * text-only history "remembers" attachments on later turns. No-ops for non-Neuron agents
 * (AttachmentDescriptionService::forAgent → null). Args passed in, so any caller can use it.
 */
trait DispatchesAttachmentDescriptionTrait
{
    protected function dispatchAttachmentDescription(
        Message $message,
        Agent $agent,
        Channel $channel
    ): void {
        $urls = [];
        $filenames = [];
        foreach ($message->files as $file) {
            if ($file->url !== '' && AttachmentDescriptionService::isDescribableFile($file)) {
                $urls[] = $file->url;
                $filenames[] = (string) $file->name;
            }
        }

        if ($urls === []) {
            return;
        }

        $user = $channel->company->getAiAgentUser() ?? $message->user;

        DescribeMessageAttachmentsJob::dispatch(
            $message->app,
            $agent,
            $user,
            CaptionTargetEnum::SOCIAL_MESSAGE,
            (string) $message->getId(),
            $urls,
            $filenames,
        );
    }
}
