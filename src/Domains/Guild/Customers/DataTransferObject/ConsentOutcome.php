<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;

final readonly class ConsentOutcome
{
    public function __construct(
        public ?ConsentSignalEnum $signal = null,
        public bool $applied = false,
        public bool $alreadyOptedOut = false,
        public int $contactsOptedOut = 0,
        public int $leadsFlagged = 0,
        public ?ConsentMatchEnum $match = null,
        public bool $narrowedRequest = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * A do-not-contact phrase that also narrowed its own scope — a channel swap or a time window.
     * Nothing is flagged; a human is asked to look, and the agent still answers.
     */
    public static function narrowedRequest(): self
    {
        return new self(match: ConsentMatchEnum::PHRASE, narrowedRequest: true);
    }

    public function isStop(): bool
    {
        return $this->signal === ConsentSignalEnum::STOP;
    }

    /**
     * A STOP ends the conversation — the caller must not spend an agent turn on it. Answering a
     * revocation with an LLM reply risks a second post-revocation message, which is the violation
     * the FCC's one-message allowance exists to bound.
     */
    public function shouldHaltAgentTurn(): bool
    {
        return $this->isStop();
    }

    public function toArray(): array
    {
        return [
            'consent_signal' => $this->signal?->value,
            'consent_applied' => $this->applied,
            'already_opted_out' => $this->alreadyOptedOut,
            'contacts_opted_out' => $this->contactsOptedOut,
            'leads_flagged' => $this->leadsFlagged,
            'consent_match' => $this->match?->value,
            'narrowed_request' => $this->narrowedRequest,
        ];
    }
}
