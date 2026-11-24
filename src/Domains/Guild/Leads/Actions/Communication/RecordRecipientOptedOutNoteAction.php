<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions\Communication;

use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

/**
 * The recipient unsubscribed with the provider itself, so the message never left. Expected, not a
 * fault: the attempt stays on the lead for whoever reads it next.
 */
final class RecordRecipientOptedOutNoteAction
{
    public const string TAG = 'sms-opt-out';

    public function __construct(
        private readonly Lead $lead
    ) {
    }

    public function execute(string $to, string $body): ?Message
    {
        return new RecordLeadNoteAction($this->lead)->execute(
            "Message not delivered: {$to} has opted out of messages. Attempted message: \"{$body}\"",
            self::TAG,
        );
    }
}
