<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Illuminate\Support\Collection;
use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Throwable;

/**
 * Runs the agent over one finished burst — a group room or an assistant 1:1 alike — and, when it
 * is allowed to speak, posts the answer back into that conversation.
 *
 * Differs from the lead-flow responder (AgentChannelResponderAction) in three ways:
 *  - no lead AI-mode guard: a per-customer switch must not mute an agent in a room, and an
 *    assistant conversation has no lead to read the switch from
 *  - the burst's media is handed to the kernel, so a photo posted with an article is actually seen
 *  - the reply goes to whatever JID the burst carries; `/api/send-message` accepts a group JID in
 *    `to` exactly like a phone number
 */
class AgentBurstResponderAction extends BaseAgentChannelReplyAction
{
    protected string $messageTypeVerb = 'whatsapp';
    protected string $communicationChannel = 'whatsapp';
    protected bool $respectsLeadAiMode = false;

    #[Override]
    public function execute(array $params = []): array
    {
        $prompt = (string) ($params['prompt'] ?? '');
        $groupJid = (string) ($params['group_jid'] ?? $this->message->message['chat_jid'] ?? '');
        $shouldReply = (bool) ($params['should_reply'] ?? false);

        if ($prompt === '' || $groupJid === '') {
            return [
                'message' => 'Burst carried nothing to answer',
                'response' => null,
            ];
        }

        $burst = $this->channel->messages()
            ->whereIn('messages.id', $params['burst_message_ids'] ?? [])
            ->get();

        $media = $this->burstMedia($burst);

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $prompt,
            user: $this->message->company->getAiAgentUserOrFail(),
            images: $media['images'],
            documents: $media['documents'],
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        // Filed whether or not we speak: this message carries `response_json` and fires the
        // message-created rule, so gating creation on the mention would discard the agent's work.
        $messageResponse = $this->createMessage(
            $responseText,
            $groupJid,
            $this->message,
            $this->channel,
            rawResponse: $responseContent
        );

        $this->carryForwardBurstAttachments($messageResponse, $burst);

        $replied = $shouldReply && ! $messageResponse->is_locked;

        if ($replied) {
            $this->sendText($groupJid, $responseText);
        } else {
            // Otherwise channel history shows an agent turn that WhatsApp never received, and
            // there is no way to tell a delivered reply from a withheld one after the fact.
            $messageResponse->addTag('not-delivered');
        }

        return [
            'message' => $prompt,
            'response' => $responseText,
            'responseText' => $responseContent,
            'replied' => $replied,
        ];
    }

    /**
     * The base carries the head's attachments onto the reply, because everything downstream reads
     * the reply — the WordPress publisher skips inbound messages outright. In a burst the head is
     * usually the caption and the photos hang off its children, so that alone copies nothing and
     * an article publishes with no featured image.
     *
     * @param Collection<int, Message> $burst
     */
    private function carryForwardBurstAttachments(Message $reply, Collection $burst): void
    {
        $already = $reply->files->pluck('id')->all();

        foreach ($burst as $part) {
            if ($part->getId() === $this->message->getId()) {
                continue;
            }

            foreach ($part->files as $file) {
                if (in_array($file->getId(), $already, true)) {
                    continue;
                }

                try {
                    $reply->addFile($file, (string) $file->name);
                    $already[] = $file->getId();
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * The one call that reaches WhatsApp. Isolated so a test can assert what would be sent without
     * a live session — the client is Guzzle-backed, so `Http::fake()` does not intercept it.
     */
    protected function sendText(string $to, string $text): void
    {
        new MessageService(
            $this->message->app,
            $this->message->company
        )->sendTextMessage($to, $text);
    }

    /**
     * Every attachment across the burst, not just the message that closed it — the article and its
     * photo are one turn as far as the agent is concerned.
     *
     * @param Collection<int, Message> $burst
     *
     * @return array{images: list<string>, documents: list<string>}
     */
    private function burstMedia(Collection $burst): array
    {
        $images = [];
        $documents = [];

        foreach ($burst as $message) {
            $urls = $message->attachmentUrls();
            $images = [...$images, ...$urls['images']];
            $documents = [...$documents, ...$urls['documents']];
        }

        return [
            'images' => array_values(array_unique($images)),
            'documents' => array_values(array_unique($documents)),
        ];
    }
}
