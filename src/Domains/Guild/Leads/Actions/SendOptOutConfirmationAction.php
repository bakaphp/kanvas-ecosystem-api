<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

/**
 * The one acknowledgement a revoked contact is allowed to receive.
 *
 * The FCC permits exactly one post-revocation message, to clarify the scope of the request. That
 * makes "send at most one" the whole job here, and it is why the text is a fixed template rather
 * than something the agent writes: an LLM asked to say goodbye can ask a follow-up question, and a
 * second message after a revocation is the violation.
 */
final class SendOptOutConfirmationAction
{
    private const string DEFAULT_MESSAGE = 'You have been unsubscribed and will not receive any further messages from us.';

    private const array SUPPORTED_CHANNELS = [
        LeadCommunicationChannelEnum::SMS->value,
        LeadCommunicationChannelEnum::WHATSAPP->value,
        LeadCommunicationChannelEnum::EMAIL->value,
    ];

    public function __construct(
        private readonly Lead $lead,
        private readonly string $channel,
    ) {
    }

    public function execute(): ?array
    {
        if (! $this->shouldSend()) {
            return null;
        }

        // Stamped before the send, not after: a timeout that actually delivered would otherwise
        // leave the lead eligible for a second acknowledgement on the next inbound STOP.
        $this->lead->set(
            ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value,
            Carbon::now()->toIso8601String(),
        );

        try {
            return new SendMessageToLeadAction($this->lead)
                ->allowOptOutConfirmation()
                ->execute(
                    $this->channel,
                    $this->message(),
                    signature: false,
                    title: 'Unsubscribed',
                );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function shouldSend(): bool
    {
        if (! in_array($this->channel, self::SUPPORTED_CHANNELS, true)) {
            return false;
        }

        if ($this->lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value) !== null) {
            return false;
        }

        // Twilio's own STOP filtering already replies on that channel; ours would be the second.
        return ! ($this->channel === LeadCommunicationChannelEnum::SMS->value && $this->twilioRepliesItself());
    }

    private function twilioRepliesItself(): bool
    {
        $configured = $this->lead->company?->get(TwilioConfigurationEnum::TWILIO_SENDS_OPT_OUT_REPLY->value)
            ?? $this->lead->app->get(TwilioConfigurationEnum::TWILIO_SENDS_OPT_OUT_REPLY->value);

        return $configured === null ? true : (bool) $configured;
    }

    private function message(): string
    {
        $configured = Str::trimToNull(
            (string) ($this->lead->company?->get(ConsentConfigurationEnum::CONFIRMATION_MESSAGE->value)
                ?? $this->lead->app->get(ConsentConfigurationEnum::CONFIRMATION_MESSAGE->value)
                ?? ''),
        );

        return $configured ?? self::DEFAULT_MESSAGE;
    }
}
