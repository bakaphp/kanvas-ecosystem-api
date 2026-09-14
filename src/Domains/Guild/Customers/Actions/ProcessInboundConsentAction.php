<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

/**
 * The one call every inbound channel makes before it spends an agent turn on a message.
 *
 * Two deterministic tiers, in order of confidence:
 *   1. an exact FCC keyword — unambiguous, applied on its own;
 *   2. a do-not-contact phrase inside a sentence — applied too, unless the message narrows its own
 *      scope ("text me instead", "don't call before 5pm"), which is a preference, not a revocation.
 *
 * Neither tier depends on the agent choosing to act, which is the point: a tool call is optional and
 * a compliance control cannot be. The agent's stop_contact tool sits behind both as the net for
 * phrasing no pattern anticipated.
 *
 * Callers must honor `shouldHaltAgentTurn()` on the result.
 */
final class ProcessInboundConsentAction
{
    /**
     * `$detectedSignal` lets a provider that classifies consent for us win over body matching —
     * Twilio's `OptOutType` is authoritative for its own numbers and can be set on a payload whose
     * body would not match on its own.
     */
    public function __construct(
        private readonly People $people,
        private readonly string $sourceChannel,
        private readonly ?string $body,
        private readonly ?Lead $lead = null,
        private readonly ?string $contactValue = null,
        private readonly ?ConsentSignalEnum $detectedSignal = null,
    ) {
    }

    public function execute(): ConsentOutcome
    {
        $signal = $this->detectedSignal ?? ConsentKeywordService::detect($this->body);

        if ($signal !== null) {
            return $this->applySignal($signal, $this->keywordMatchTier($signal));
        }

        if (! ConsentKeywordService::matchesNoContactPhrase($this->body)) {
            return ConsentOutcome::none();
        }

        if (ConsentKeywordService::narrowsRequestScope($this->body)) {
            $this->flagForHuman();

            return ConsentOutcome::narrowedRequest();
        }

        return $this->applySignal(ConsentSignalEnum::STOP, ConsentMatchEnum::PHRASE);
    }

    /**
     * A provider's own classification stays EXACT even for an ambiguous word: Twilio's OptOutType
     * means the carrier already unsubscribed the number, which is an act, not a reading of the text.
     * Only our own keyword match can be second-guessed.
     */
    private function keywordMatchTier(ConsentSignalEnum $signal): ConsentMatchEnum
    {
        if ($signal !== ConsentSignalEnum::STOP || $this->detectedSignal !== null) {
            return ConsentMatchEnum::EXACT;
        }

        return ConsentKeywordService::stopKeywordIsAmbiguous($this->body)
            ? ConsentMatchEnum::AMBIGUOUS_KEYWORD
            : ConsentMatchEnum::EXACT;
    }

    private function applySignal(ConsentSignalEnum $signal, ConsentMatchEnum $match): ConsentOutcome
    {
        return match ($signal) {
            ConsentSignalEnum::STOP => new ApplyDoNotContactAction(
                people: $this->people,
                sourceChannel: $this->sourceChannel,
                lead: $this->lead,
                reason: ConsentKeywordService::extractReason($this->body),
                match: $match,
            )->execute(),
            // "YES" is a re-subscribe keyword only for someone who is actually opted out. For everyone
            // else it is the commonest affirmative reply there is — "yes, book me in" — and treating
            // it as a consent event silences the agent on the most engaged message a prospect sends.
            ConsentSignalEnum::START => $this->isOptedOut()
                ? new RevokeDoNotContactAction(
                    people: $this->people,
                    sourceChannel: $this->sourceChannel,
                    lead: $this->lead,
                    contactValue: $this->contactValue,
                )->execute()
                : ConsentOutcome::none(),
            // HELP is a carrier-level keyword the provider answers itself; nothing to apply here.
            ConsentSignalEnum::HELP => new ConsentOutcome(signal: $signal, match: $match),
        };
    }

    private function isOptedOut(): bool
    {
        return (bool) $this->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value);
    }

    /**
     * A note, and deliberately nothing else.
     *
     * HandOffAction would be the obvious way to get a human's attention here and it is the wrong one:
     * `COMPLIANCE_INTERNAL` opts out every phone the person has, and *any* handoff type sets
     * `agent_hand_off`, which terminally exhausts follow-up. Both are precisely the damage this
     * carve-out exists to prevent — the customer asked to be reached differently, not less.
     *
     * The inbound path already notifies stakeholders about the message itself; this note is what tells
     * whoever reads it that the wording came close enough to an opt-out to be worth a second look.
     */
    private function flagForHuman(): void
    {
        if ($this->lead === null) {
            return;
        }

        try {
            new RecordLeadNoteAction($this->lead)->execute(
                sprintf(
                    'Customer message reads like a contact-preference change rather than an opt-out, '
                        . 'so nothing was flagged and the agent replied normally. Their words: "%s"',
                    trim((string) $this->body),
                ),
                'consent-review',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
