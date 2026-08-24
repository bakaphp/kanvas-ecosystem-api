<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;
use Throwable;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    private const string WORKING = ':hourglass_flowing_sand: working on it…';
    private const string FAILED = ':warning: Something went wrong on my side. Try again.';
    private const string REPLY_UNDELIVERABLE = ':white_check_mark: Done — but my reply was too long to post here.';

    protected string $messageTypeVerb = 'slack';
    protected string $communicationChannel = 'slack';
    protected bool $supportsHumanApproval = false;

    // "AI mode off" is a per-lead, customer-facing switch. It must not mute an internal teammate.
    protected bool $respectsLeadAiMode = false;

    #[Override]
    public function execute(array $params = []): array
    {
        $client = Client::getInstanceByAgent($this->agent);
        $slackChannelId = (string) ($this->message->message['slack_channel'] ?? '');
        $threadTs = (string) ($this->message->message['slack_thread_ts'] ?? '');
        $inboundText = (string) ($this->message->message['content'] ?? '');

        // Files the user dropped in Slack were re-hosted on the message at ingest — split them into the
        // native image bucket and the audio/PDF/doc bucket so the agent can actually see them.
        ['images' => $imageUrls, 'documents' => $documentUrls] = $this->message->attachmentUrls();
        $inboundText = AttachmentPromptBuilder::withAttachments($inboundText, $documentUrls);

        // Slack was ack'd the moment the event arrived; a turn with tool calls runs 10-30s after
        // that. Post a placeholder so the thread shows the agent working, not silence. The answer
        // then replaces it as a fresh post — an edit would notify nobody.
        $placeholderTs = $client->postMessage(
            $slackChannelId,
            self::WORKING,
            $threadTs
        );

        try {
            $response = new AgentChatKernel(
                agent: $this->agent,
                session: $this->session,
                message: $inboundText,
                // The human is the actor here, not the AI service user: Slack is an internal
                // surface, so the agent is talking to a teammate it should be able to name.
                user: $this->message->user,
                images: $imageUrls,
                sourceChannel: $this->channel,
                sourceMessage: $this->message,
                documents: $documentUrls,
                persistConversation: false,
            )->execute();
        } catch (Throwable $e) {
            $client->updateMessage($slackChannelId, $placeholderTs, self::FAILED);

            throw $e;
        }

        $responseText = ChatHelper::extractTextFromResponse($response);
        $reply = $this->createMessage(
            $responseText,
            $slackChannelId,
            $this->message,
            $this->channel
        );

        if (! $reply->is_locked) {
            try {
                $client->replacePlaceholderWithReply(
                    $slackChannelId,
                    $placeholderTs,
                    SlackMarkdownService::toMrkdwn($responseText),
                    $threadTs !== '' ? $threadTs : null,
                );
            } catch (Throwable $e) {
                report($e);

                try {
                    $client->updateMessage(
                        $slackChannelId,
                        $placeholderTs,
                        self::REPLY_UNDELIVERABLE
                    );
                } catch (Throwable) {
                }
            }
        }

        return [
            'response' => $responseText,
            'message_id' => $reply->getId(),
        ];
    }
}
