<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Enums\MessageStatusEnum;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

/**
 * Sends a failed outbound SMS again, as the same message: same body and attachments, to the number and
 * from the sender of its last attempt. The new SID replaces the old one, so the status callbacks, the
 * `delivery` body key and the Pusher push all follow the new attempt.
 *
 * Every send guard still applies (do-not-contact, opt-out, the mobile-only Lookup) — a resend is a
 * person deciding to try again, not a way around a stop request.
 */
class ResendFailedMessageAction
{
    private const int LOCK_SECONDS = 60;

    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(): Message
    {
        $lead = $this->message->entity(Lead::class);
        if (! $lead instanceof Lead) {
            throw new ValidationException('This message is not linked to a lead, so there is no one to resend it to');
        }

        $content = trim((string) ($this->message->getMessage()['content'] ?? ''));
        $files = $this->message->files()->get();
        if ($content === '' && $files->isEmpty()) {
            throw new ValidationException('This message has no text or attachments to resend');
        }

        // A double click must not text the person twice; the status is re-read under the lock because
        // the first resend moves it off failed.
        $lock = Cache::lock('twilio:resend:' . $this->message->getId(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new ValidationException('This message is already being resent');
        }

        try {
            $status = MessageStatusEnum::fromTwilio($this->message->get(CustomFieldEnum::CURRENT_STATUS->value));
            if ($status?->isFailure() !== true) {
                throw new ValidationException(sprintf(
                    'Only a failed or undelivered Twilio message can be resent; this one is %s',
                    $status?->value ?? 'not a Twilio message',
                ));
            }

            $lastAttempt = MessageAttempt::query()
                ->where('message_id', $this->message->getId())
                ->latest('id')
                ->first();

            $result = $this->sender($lead)->execute(
                channel: LeadCommunicationChannelEnum::SMS->value,
                message: $content,
                from: $lastAttempt?->from_number,
                files: $files->isNotEmpty() ? $files : null,
                to: $lastAttempt?->to_number,
            );

            new StoreMessageSidAction($this->message)->execute($result);
        } finally {
            $lock->release();
        }

        return $this->message;
    }

    protected function sender(Lead $lead): SendMessageToLeadAction
    {
        return new SendMessageToLeadAction($lead);
    }
}
