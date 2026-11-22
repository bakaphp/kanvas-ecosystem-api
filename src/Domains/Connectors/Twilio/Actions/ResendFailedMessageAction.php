<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Communication\Messages\Actions\RetryDeliveryAction;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;

/**
 * Sends a failed outbound text again, as the same message: its delivery is retried, so the same
 * body and attachments go to the same number from the same connection, and the outcome is written
 * back onto this message.
 *
 * Every send guard still applies (do-not-contact, opt-out, the line-type Lookup) — a resend is a
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
        // A double click must not text the person twice; the status is re-read under the lock because
        // the first resend moves it off failed.
        $lock = Cache::lock('twilio:resend:' . $this->message->getId(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new ValidationException('This message is already being resent');
        }

        try {
            $delivery = Delivery::query()
                ->fromApp($this->message->app)
                ->fromCompany($this->message->company)
                ->where('message_id', $this->message->getId())
                ->latest('id')
                ->first();

            if (! $delivery instanceof Delivery) {
                throw new ValidationException('This message has no delivery on record, so there is nothing to resend');
            }

            if (! new RetryDeliveryAction($delivery)->execute()) {
                $delivery->refresh();

                throw new ValidationException(sprintf(
                    'Only a failed or undelivered message can be resent; this one is %s%s',
                    $delivery->status->value,
                    $delivery->error_code !== null ? ' (' . $delivery->error_code . ')' : '',
                ));
            }
        } finally {
            $lock->release();
        }

        return $this->message;
    }
}
