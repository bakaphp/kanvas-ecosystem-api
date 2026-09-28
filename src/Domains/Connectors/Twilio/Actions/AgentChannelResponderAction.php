<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Twilio\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Override;
use Twilio\Exceptions\RestException;

class AgentChannelResponderAction extends BaseAgentChannelReplyAction
{
    /**
     * Twilio error code returned when the recipient has opted out (texted STOP)
     * and can no longer be messaged. See https://www.twilio.com/docs/api/errors/21610.
     */
    private const int TWILIO_UNSUBSCRIBED_RECIPIENT_CODE = 21610;

    protected string $messageTypeVerb = 'twilio-sms';
    protected string $communicationChannel = 'sms';

    #[Override]
    public function execute(array $params = []): array
    {
        $entity = $this->message->entity();
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        // The burst that closed is already one turn — FlushSmsBurstAction hands over every text in
        // it as a single prompt, so the agent answers the flurry rather than its first line.
        $messageConversation = Str::trimToNull($params['burst_text'] ?? null)
            ?? $this->message->message['content'];

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageConversation,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $entity instanceof Lead ? $entity : null,
            sourceChannel: $this->channel,
            sourceMessage: $this->message,
            persistConversation: false,
        )->execute();

        $responseText = ChatHelper::extractTextFromResponse($responseContent);

        // A customer who texts us cold still gets the clause on our first reply. Applied
        // before createMessage so the approval-hold branch stores the body it will ship.
        $responseText = SmsOptOutNoticeService::appendIfFirstOutbound($this->channel, $responseText);

        $to = Str::toE164(Str::replace('twilio-', '', $this->channel->slug));
        $to = $this->hijackMessagePhone($to);

        $messageResponse = $this->createMessage(
            $responseText,
            $to,
            $this->message,
            $this->channel,
            $params['from']
        );

        if (! $messageResponse->is_locked) {
            $this->sendResponse($to, $params['from'], $responseText);
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }

    protected function sendResponse(string $to, string $from, string $body): void
    {
        try {
            $this->dispatchMessage($to, $from, $body);
        } catch (RestException $e) {
            // Recipient replied STOP → Twilio auto-unsubscribed them and rejects
            // any further reply with 21610. That's an expected opt-out, not a
            // fault: swallow it so the activity doesn't retry 3× and spam Sentry
            if ($e->getCode() !== self::TWILIO_UNSUBSCRIBED_RECIPIENT_CODE) {
                throw $e;
            }

            $this->recordOptOutNote($to, $body);
        }
    }

    private function recordOptOutNote(string $to, string $body): void
    {
        $entity = $this->message->entity();
        if (! $entity instanceof Lead) {
            return;
        }

        $entity->people?->optOutPhoneContacts();

        new RecordLeadNoteAction($entity)->execute(
            "SMS not delivered: {$to} has opted out of messages (replied STOP). Attempted reply: \"{$body}\"",
            'sms-opt-out',
        );
    }

    protected function dispatchMessage(string $to, string $from, string $body): void
    {
        Client::getInstanceByCompany($this->message->company)
            ->messages->create(
                $to,
                [
                    'from' => $from,
                    'body' => $body,
                ]
            );
    }
}
