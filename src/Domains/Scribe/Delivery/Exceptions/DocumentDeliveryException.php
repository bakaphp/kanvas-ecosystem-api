<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Exceptions;

use RuntimeException;

/**
 * A send that must not happen yet, with a machine reason the agent tools hand back so the agent knows
 * whether to ask someone (no contact), wait (e-CF not authorized) or fix setup.
 */
class DocumentDeliveryException extends RuntimeException
{
    public const string NO_CONTACT = 'no_billing_contact';
    public const string NO_DESTINATION = 'no_destination';
    public const string DO_NOT_CONTACT = 'do_not_contact';
    public const string WHATSAPP_UNAVAILABLE = 'whatsapp_not_configured';
    public const string ECF_NOT_ACCEPTED = 'ecf_not_accepted';
    public const string INVALID_STATUS = 'invalid_status';
    public const string NOT_SET_UP = 'document_actions_not_set_up';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * True when only this recipient cannot get the document; the others still can.
     */
    public function isAboutRecipient(): bool
    {
        return in_array($this->reason, [self::NO_DESTINATION, self::DO_NOT_CONTACT, self::WHATSAPP_UNAVAILABLE], true);
    }
}
