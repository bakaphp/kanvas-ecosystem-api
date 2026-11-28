<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Messages\DataTransferObject\InboundTurn;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Exceptions\InboundNotAnsweredException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\AttachmentPromptBuilder;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * An agent's answer to an inbound message, on the conversation the message arrived on. The medium,
 * the counterpart and the sending connection all come from the conversation; the reply is filed
 * first (and may be held for approval), then delivered by the facade on that same message.
 *
 * Whether the message may be answered at all is settled here, when the turn runs, for every path
 * that reaches it: the live turn and the one a support-mode lead's delay queued minutes earlier.
 */
class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    private readonly InboundTurn $turn;

    /**
     * @param InboundTurn|null $turn when the caller cleared the message a moment ago to learn who
     *                               answers; read here otherwise
     *
     * @throws InboundNotAnsweredException when the connection is off, or is not this agent's to answer
     */
    public function __construct(
        Channel $channel,
        Message $message,
        Agent $agent,
        ?Session $session = null,
        ?InboundTurn $turn = null
    ) {
        // Ahead of the base constructor, which already acts on the lead (its follow-up, the
        // attachment descriptions): none of that is owed to a turn nobody answers.
        $this->turn = $turn ?? InboundTurn::of($message);

        if (! $this->turn->agent->is($agent)) {
            throw new InboundNotAnsweredException(
                'owner_changed',
                $this->turn->connection->account_key . ' is answered by another agent now'
            );
        }

        parent::__construct(
            $channel,
            $message,
            $agent,
            $session
        );

        // The reply is filed as the same kind of message it answers, so whatever reads a
        // conversation by type (the CRM's channel views, the delivery status jobs) finds both.
        $this->communicationChannel = $this->turn->medium->value;
        $this->messageTypeVerb = $message->messageType?->verb ?? $this->communicationChannel;
        // A text is answered in seconds; an email can wait for a person to read it first.
        $this->supportsHumanApproval = $this->isEmail();
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $lead = $this->message->entity(Lead::class);

        // A closed burst is one turn: every text in it reaches the agent as a single prompt, and
        // every picture in it as an attachment. A picture sent without words is still a turn.
        $inboundText = Str::trimToNull($params['burst_text'] ?? null) ?? $this->message->message['content'] ?? null;
        $burstMessageIds = $params['burst_message_ids'] ?? [];
        ['text' => $prompt, 'images' => $images, 'documents' => $documents] = $this->inboundContent($inboundText, $burstMessageIds);

        if ($this->isEmail()) {
            // On top of the native blocks: the markers let an internal agent open a file with its tools.
            $prompt = $this->asEmailToOwnMailbox(AttachmentPromptBuilder::withFilesystemMarkers($prompt, $this->message->files));
        }

        $response = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $prompt,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $lead instanceof Lead ? $lead : null,
            images: $images,
            documents: $documents,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
            // The fallback copy is written for staff, and an email would carry it to whoever wrote
            // in (a vendor got it, KANVAS-ECOSYSTEM-6GW). A failed email turn sends nothing and
            // fails the job instead.
            fallbackOnFailure: ! $this->isEmail(),
            burstMessageIds: $burstMessageIds,
        )->execute();

        // Before the reply is filed, so a reply held for approval stores the body it will ship.
        $text = $this->prepareReply(ChatHelper::extractTextFromResponse($response));

        $reply = $this->createMessage(
            $text,
            $this->turn->recipient,
            $this->message,
            $this->channel,
            $this->turn->connection->account_key,
            rawResponse: $response
        );

        $reply->addMessage([DeliverAgentReplyAction::ANSWERS_KEY => $this->message->getId()]);

        // A reply held for approval ships from the same action once a person approves it.
        if (! $reply->is_locked) {
            new DeliverAgentReplyAction($this->message, $reply, $this->agent)->execute();
        }

        return [
            'message' => $prompt,
            'responseText' => $response,
            'response' => $text,
        ];
    }

    /**
     * A customer who writes to us cold still gets the opt-out clause on our first text.
     */
    private function prepareReply(string $text): string
    {
        return in_array($this->turn->medium, [ChannelTypeEnum::SMS, ChannelTypeEnum::MMS], true)
            ? SmsOptOutNoticeService::appendIfFirstOutbound($this->channel, $text)
            : $text;
    }

    /**
     * The body alone reads like a chat message. A company line is left as written: its agents are
     * tuned on the bare text.
     */
    private function asEmailToOwnMailbox(string $prompt): string
    {
        $mailbox = $this->turn->connection;

        if (! $mailbox->isPersonalAddressOf($this->agent)) {
            return $prompt;
        }

        $subject = InboundTurn::subjectOf($this->message);

        return sprintf(
            "[Email from %s to your own address %s%s. Your answer is emailed back to them as you write it.]\n\n%s",
            $this->turn->recipient,
            $mailbox->account_key,
            $subject !== null ? ', subject "' . $subject . '"' : '',
            $prompt
        );
    }

    private function isEmail(): bool
    {
        return $this->turn->medium === ChannelTypeEnum::EMAIL;
    }
}
