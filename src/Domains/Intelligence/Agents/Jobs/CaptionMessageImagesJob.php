<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\CaptionTargetEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\ImageCaptionService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Backfills text captions for the images of a single chat message, using the agent's own model,
 * so the agent's text-only history "remembers" what each image was on later turns. Runs async —
 * the live turn already saw the real bytes, so the caption only has to exist by the NEXT turn,
 * keeping reply latency untouched.
 */
final class CaptionMessageImagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<string> $imageUrls
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Agent $agent,
        public readonly Users $user,
        public readonly CaptionTargetEnum $target,
        public readonly string $targetId,
        public readonly array $imageUrls,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->imageUrls === []) {
            return;
        }

        $captioner = ImageCaptionService::forAgent($this->agent, $this->user);

        if ($captioner === null) {
            return;
        }

        $captions = $captioner->captionUrls($this->imageUrls);

        if (array_filter($captions) === []) {
            return;
        }

        match ($this->target) {
            CaptionTargetEnum::SOCIAL_MESSAGE => $this->writeToSocialMessage($captions),
            CaptionTargetEnum::CONVERSATION_MESSAGE => $this->writeToConversationMessage($captions),
        };
    }

    /**
     * @param list<string> $captions
     */
    private function writeToSocialMessage(array $captions): void
    {
        try {
            $message = Message::getById((int) $this->targetId, $this->app);
        } catch (Throwable) {
            return;
        }

        $message->addMessage(['image_descriptions' => array_values(array_filter($captions))]);
    }

    /**
     * @param list<string> $captions
     */
    private function writeToConversationMessage(array $captions): void
    {
        $attachments = [];
        foreach ($this->imageUrls as $i => $url) {
            $attachments[] = ['url' => $url, 'caption' => $captions[$i] ?? ''];
        }

        DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('id', $this->targetId)
            ->update([
                'attachments' => json_encode($attachments),
                'updated_at' => now(),
            ]);
    }
}
