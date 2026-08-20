<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Services\MessageService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;

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

        $media = $this->burstMedia($params['burst_message_ids'] ?? []);

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

        // Filed whether or not we speak. The message carries `response_json` and fires the
        // message-created rule, so a conversation the agent stays silent in still publishes its
        // work — that is what "listens quietly, publishes the article, answers only when
        // addressed" means. Gating message creation on the mention would throw the article away.
        $messageResponse = $this->createMessage(
            $responseText,
            $groupJid,
            $this->message,
            $this->channel,
            rawResponse: $responseContent
        );

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
     * @param array<int, int> $burstMessageIds
     *
     * @return array{images: list<string>, documents: list<string>}
     */
    private function burstMedia(array $burstMessageIds): array
    {
        $images = [];
        $documents = [];

        foreach ($this->channel->messages()->whereIn('messages.id', $burstMessageIds)->get() as $message) {
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
