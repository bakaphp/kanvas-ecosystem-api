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

        // A silent group still runs the agent — the activities wired to the burst consume its
        // output. Only the posting back is gated.
        if (! $shouldReply) {
            return [
                'message' => $prompt,
                'response' => $responseText,
                'responseText' => $responseContent,
                'replied' => false,
            ];
        }

        $messageResponse = $this->createMessage(
            $responseText,
            $groupJid,
            $this->message,
            $this->channel,
            rawResponse: $responseContent
        );

        if (! $messageResponse->is_locked) {
            new MessageService(
                $this->message->app,
                $this->message->company
            )->sendTextMessage($groupJid, $responseText);
        }

        return [
            'message' => $prompt,
            'response' => $responseText,
            'responseText' => $responseContent,
            'replied' => true,
        ];
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
