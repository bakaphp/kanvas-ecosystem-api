<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

/**
 * Who may write in on an account, and what writing in makes of them. A customer line and an
 * agent's own mailbox differ in exactly this: on the first anyone is a prospect, on the second most
 * mail is from colleagues and people already on file, and a stranger is not a lead.
 */
final readonly class InboundSenderPolicy
{
    /**
     * @param bool $keepsPersonAsFound a person already on file is not renamed, re-tagged or given
     *                                 the message's fields
     */
    private function __construct(
        public bool $recognizesTeammates,
        public bool $knownSendersOnly,
        public bool $opensLead,
        public bool $keepsPersonAsFound
    ) {
    }

    /**
     * Whoever writes is a person, and a person with no open lead gets one.
     *
     * @param bool $keepsPersonAsFound for a session that stands in for someone else's number, whose
     *                                 messages say nothing about the person filed
     */
    public static function customers(bool $keepsPersonAsFound = false): self
    {
        return new self(
            recognizesTeammates: false,
            knownSendersOnly: false,
            opensLead: true,
            keepsPersonAsFound: $keepsPersonAsFound
        );
    }

    /**
     * An agent's own address. A user of the company writes as themselves, a person is filed on
     * what they already have, and a stranger gets in only when the mailbox is open — as a person,
     * never as a lead.
     */
    public static function assistant(bool $acceptsStrangers): self
    {
        return new self(
            recognizesTeammates: true,
            knownSendersOnly: ! $acceptsStrangers,
            opensLead: false,
            keepsPersonAsFound: true
        );
    }
}
