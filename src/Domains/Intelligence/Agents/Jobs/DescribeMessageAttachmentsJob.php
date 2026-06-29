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
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Backfills text descriptions for a chat message's attachments (image caption / audio transcript /
 * PDF summary), using the agent's own model, so the agent's text-only history "remembers" what each
 * attachment was on later turns. Runs async — the live turn already saw the real bytes, so the
 * description only has to exist by the NEXT turn, keeping reply latency untouched.
 */
final class DescribeMessageAttachmentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<string> $attachmentUrls
     * @param list<string> $filenames Optional original filenames, index-aligned with $attachmentUrls.
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Agent $agent,
        public readonly Users $user,
        public readonly CaptionTargetEnum $target,
        public readonly string $targetId,
        public readonly array $attachmentUrls,
        public readonly array $filenames = [],
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->attachmentUrls === []) {
            return;
        }

        $describer = AttachmentDescriptionService::forAgent($this->agent, $this->user);

        if ($describer === null) {
            return;
        }

        $descriptions = $describer->describeUrls($this->attachmentUrls, $this->filenames);

        if (array_filter($descriptions) === []) {
            return;
        }

        match ($this->target) {
            CaptionTargetEnum::SOCIAL_MESSAGE => $this->writeToSocialMessage($descriptions),
            CaptionTargetEnum::CONVERSATION_MESSAGE => $this->writeToConversationMessage($descriptions),
            CaptionTargetEnum::AGENT_HISTORY => $this->writeToAgentHistory($descriptions),
        };
    }

    /**
     * @param list<string> $descriptions
     */
    private function writeToSocialMessage(array $descriptions): void
    {
        try {
            $message = Message::getById((int) $this->targetId, $this->app);
        } catch (Throwable) {
            return;
        }

        $message->addMessage(['attachment_descriptions' => array_values(array_filter($descriptions))]);
    }

    /**
     * @param list<string> $descriptions
     */
    private function writeToConversationMessage(array $descriptions): void
    {
        $attachments = [];
        foreach ($this->attachmentUrls as $i => $url) {
            $attachments[] = ['url' => $url, 'caption' => $descriptions[$i] ?? ''];
        }

        DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('id', $this->targetId)
            ->update([
                'attachments' => json_encode($attachments),
                'updated_at' => now(),
            ]);
    }

    /**
     * The Laravel path (KanvasLaravelAgent::messages()) rebuilds history straight from
     * input.content with no marker transform, so fold the description into that text directly.
     *
     * @param list<string> $descriptions
     */
    private function writeToAgentHistory(array $descriptions): void
    {
        try {
            $history = AgentHistory::getById((int) $this->targetId, $this->app);
        } catch (Throwable) {
            return;
        }

        $marker = implode(
            ' ',
            array_map(
                static fn (string $description): string => "[Attachment: {$description}]",
                array_values(array_filter($descriptions)),
            ),
        );

        if ($marker === '') {
            return;
        }

        $input = $history->input ?? [];
        $input['content'] = trim((string) ($input['content'] ?? '') . "\n" . $marker);
        $history->input = $input;
        $history->saveOrFail();
    }
}
