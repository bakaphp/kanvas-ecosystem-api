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
 * Queues the image/audio/PDF description backfill onto a channel-inbound Social message.
 * Shared by every channel responder (connector + internal) so the file-scan + dispatch
 * lives in one place — the describe Job stays a pure "given URLs, caption them" worker.
 *
 * Inbound attachments arrive as filesystem files, not in the message JSON, so the text-only
 * history loader can't "see" them on later turns. This describes them async with the agent's
 * own model and stashes the text on the message. No-ops for non-Neuron agents
 * (AttachmentDescriptionService::forAgent → null).
 *
 * Everything it needs is passed in, so any caller (responder, command, job) can use it.
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
