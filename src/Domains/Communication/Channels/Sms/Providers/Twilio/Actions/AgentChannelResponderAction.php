<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions;

use Baka\Support\Str;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Client;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Services\TwilioReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Throwable;
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
        $entity = $this->message->preferredEntity(Lead::class);
        if ($entity === null) {
            throw new ValidationException('No entity found');
        }

        // The burst that closed is already one turn — FlushSmsBurstAction hands over every text in
        // it as a single prompt, so the agent answers the flurry rather than its first line.
        ['text' => $messageConversation, 'images' => $images, 'documents' => $documents] = $this->inboundContent(
            Str::trimToNull($params['burst_text'] ?? null) ?? $this->message->message['content'] ?? null
        );

        $responseContent = new AgentChatKernel(
            agent: $this->agent,
            session: $this->session,
            message: $messageConversation,
            user: $this->message->company->getAiAgentUserOrFail(),
            currentLead: $entity instanceof Lead ? $entity : null,
            images: $images,
            documents: $documents,
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

        $from = $this->agent->smsPhoneNumber() ?? $params['from'];

        $messageResponse = $this->createMessage(
            $responseText,
            $to,
            $this->message,
            $this->channel,
            $from
        );

        if (! $messageResponse->is_locked) {
            $this->sendResponse(
                $messageResponse,
                $to,
                $from,
                $responseText
            );
        }

        return [
            'message' => $messageConversation,
            'responseText' => $responseContent,
            'response' => $responseText,
        ];
    }

    /**
     * The send result goes through StoreMessageSidAction like every other lead SMS, so the reply carries
     * its SID and the status callback can find it.
     */
    protected function sendResponse(
        Message $outbound,
        string $to,
        string $from,
        string $body
    ): void {
        try {
            $twilioMessage = $this->dispatchMessage($to, $from, $body);
        } catch (RestException $e) {
            // Recipient replied STOP → Twilio auto-unsubscribed them and rejects
            // any further reply with 21610. That's an expected opt-out, not a
            // fault: swallow it so the activity doesn't retry 3× and spam Sentry
            if ($e->getCode() !== self::TWILIO_UNSUBSCRIBED_RECIPIENT_CODE) {
                throw $e;
            }

            $this->recordOptOutNote($to, $body);
            new StoreMessageSidAction($outbound)->execute([
                'channel' => LeadCommunicationChannelEnum::SMS->value,
                'messages' => [],
                'classification' => 'opted_out',
                'retryable' => false,
                'twilio_error_code' => self::TWILIO_UNSUBSCRIBED_RECIPIENT_CODE,
                'error' => $e->getMessage(),
                'from' => $from,
                'to' => $to,
            ]);

            return;
        }

        // The SMS is already out: a failed write here must not fail the activity, whose retry would
        // text the customer a second time.
        try {
            new StoreMessageSidAction($outbound)->execute([
                'channel' => LeadCommunicationChannelEnum::SMS->value,
                'messages' => [Client::describeMessage($twilioMessage)],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function recordOptOutNote(string $to, string $body): void
    {
        $entity = $this->message->entity(Lead::class);
        if (! $entity instanceof Lead) {
            return;
        }

        $entity->people?->optOutPhoneContacts();

        new RecordLeadNoteAction($entity)->execute(
            "SMS not delivered: {$to} has opted out of messages (replied STOP). Attempted reply: \"{$body}\"",
            'sms-opt-out',
        );
    }

    protected function dispatchMessage(string $to, string $from, string $body): object
    {
        $payload = [
            'from' => $from,
            'body' => $body,
        ];

        $statusCallbackUrl = new TwilioReceiverService()->statusCallbackUrl($this->message->app, $this->message->company);
        if ($statusCallbackUrl !== null) {
            $payload['statusCallback'] = $statusCallbackUrl;
        }

        return Client::getInstanceByCompany($this->message->company)->messages->create($to, $payload);
    }
}
