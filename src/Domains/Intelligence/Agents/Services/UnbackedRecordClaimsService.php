<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

/**
 * Document identifiers a reply states that nothing in the conversation backs: not a tool result of this turn, not
 * the person's own words, not an earlier turn. A model that writes "Invoice #23026, submitted for approval" without
 * calling a tool states a record that does not exist, and in accounting a person acts on it.
 *
 * Only identifiers named next to a document word count, so an amount or a date is never mistaken for one.
 */
final class UnbackedRecordClaimsService
{
    public const string WARNING = '_⚠️ Not verified: nothing in this conversation returned %s. Treat it as not done '
        . 'until it is checked._';

    /**
     * "Invoice #23026", "Quote ID: `3672`", "factura E310000000010": the label, then any punctuation or markdown
     * (backticks, bold, parentheses), then the id.
     */
    private const string CLAIM = '/\b(?:invoice|quote|bill|payment|credit note|debit note|receipt|factura|cotizaci[oó]n|'
        . 'nota de cr[eé]dito|nota de d[eé]bito|e-?ncf|ncf)(?:\s+(?:id|number|no\.?|n[uú]mero))?[\s:#*\x60"\'()_]*'
        . '([A-Z]{0,4}\d[\dA-Z-]{2,})\b/iu';

    /**
     * The files Kanvas names after a record: quote-draft-3672.pdf, sow-draft-3672-r1.pdf, e-cf-E310000000010.pdf.
     */
    private const string FILE = '/\b(?:invoice|quote|sow|bill|receipt|e-cf)-(?:draft-)?([A-Z]{0,4}\d[\dA-Z]*)(?:-r\d+)?\.(?:pdf|xml)\b/i';

    public const string CORRECTION = 'Your draft reply named %s, which no tool returned in this conversation, so none of it '
        . 'exists and nothing was done. Answer again: do the work with your tools now and report only what they return, '
        . 'or tell the person plainly what was not done and what you need. Never state an id, number, file or link '
        . 'that a tool did not return. Never describe a result, refusal or error from a tool you did not call this '
        . 'turn: if you are not calling it, say you have not run it yet.';

    /**
     * A turn that carries a file: the model can read the numbers off it, but only a tool result backs them, and a
     * question it was asking must come back as a question, not as the write it was asking permission for.
     */
    public const string ATTACHMENT_CORRECTION = 'Your draft reply named %s, which no tool returned in this conversation. '
        . 'This turn carries an attached file; reading it yourself does not count, only a tool result does. Answer '
        . 'again: run the read tool for the attachment first (extract_invoice_data for an invoice or receipt) and '
        . 'report what it returns. If your draft was asking the person something, ask it again from the tool result; '
        . 'do not create, book or change anything they have not answered. Never state an id, number, file or link '
        . 'that a tool did not return. Never describe a result, refusal or error from a tool you did not call this '
        . 'turn: if you are not calling it, say you have not run it yet.';

    /**
     * Earlier turns omit their tool calls, so a denial of past work needs a lookup in the current turn.
     */
    private const string DENIAL = '/\b(?:hallucinat\w*|without\s+(?:actually\s+)?(?:executing|calling|running|using)\b|'
        . '(?:was|were)\s+(?:never|not)\s+(?:actually\s+)?(?:created|issued|sent|recorded|generated|executed|made|converted)\b|'
        . 'never\s+(?:actually\s+)?(?:created|executed|ran|called|issued|recorded|sent|made)\b|'
        . '(?:does|did|do)\s+not\s+(?:actually\s+)?exist\b|'
        . 'no\s+(?:invoice|quote|bill|payment|record|credit\s+note|debit\s+note)\s+(?:exists|was\s+(?:created|made|issued)))/iu';

    public const string DENIAL_CORRECTION = 'Your draft reply said "%s" without calling any tool this turn. The tool calls '
        . 'behind your earlier turns are not shown to you, and a "[card shown: …]" line in one of them is a card a tool '
        . 'produced then. You cannot know from memory whether an earlier action happened or what state a record is in. '
        . 'Answer again: look the records up with your lookup tools now and report exactly what they return. Never call an '
        . 'earlier turn a hallucination, and never declare a record missing or unchanged, unless a tool in this turn says so.';

    public const string DENIAL_WARNING = '_⚠️ Not verified: this reply says earlier work did not happen, but no tool checked '
        . 'it. Confirm the record before acting on this._';

    /**
     * A real record id does not prove an action happened: completion claims also need a tool call this turn.
     */
    private const string COMPLETION = '/\b(?:(?:has|have)\s+been|(?:is|are)\s+now|was|were|(?:is|are)\s+(?:officially|successfully))'
        . '\s+(?:(?:now|officially|successfully|already|also)\s+)*'
        . '(?:recorded|accepted|created|issued|sent|submitted|approved|discarded|voided|converted|activated|cancell?ed|'
        . 'generated|updated|marked|posted|registered)\b|'
        . '\b(?:ha\s+sido|han\s+sido|fue|fueron|qued[oó]|quedaron)\s+'
        . '(?:registrad|aceptad|cread|emitid|enviad|aprobad|anulad|convertid|activad|cancelad)[oa]s?\b/iu';

    private const string DOCUMENT = '/\b(?:invoice|quote|bill|payment|credit\s+note|debit\s+note|receipt|sow|schedule|'
        . 'factura|cotizaci[oó]n|e-?ncf)\b/iu';

    public const string COMPLETION_CORRECTION = 'Your draft reply said "%s" without calling any tool this turn, so nothing '
        . 'was done and nothing was checked. Answer again: do the work with your tools now and report only what they '
        . 'return, or look the record up and report the state the tool shows, or tell the person plainly what was not '
        . 'done and what you need. Never say a record was created, accepted, sent or changed unless a tool in this turn '
        . 'says so, and never describe a result, refusal or error from a tool you did not call this turn.';

    public const string COMPLETION_WARNING = '_⚠️ Not verified: this reply says work was done, but no tool ran this turn. '
        . 'Treat it as not done until it is checked._';

    /**
     * @return list<string>
     */
    public function find(string $reply, string $evidence): array
    {
        preg_match_all(self::CLAIM, $reply, $claims);
        preg_match_all(self::FILE, $reply, $files);

        return array_values(array_filter(
            array_unique([...$claims[1], ...$files[1]]),
            fn (string $id): bool => ! str_contains($evidence, $id)
        ));
    }

    /**
     * @param list<string> $ids
     */
    public function correction(array $ids, bool $hasAttachment = false): string
    {
        return sprintf($hasAttachment ? self::ATTACHMENT_CORRECTION : self::CORRECTION, implode(', ', $ids));
    }

    /**
     * @param list<string> $ids
     */
    public function warning(array $ids): string
    {
        return sprintf(self::WARNING, implode(', ', $ids));
    }

    /**
     * The denial a reply makes, when the turn ran no tool to back it; null when it makes none or a tool ran.
     *
     * @param list<array<string, mixed>> $toolCalls the turn's tool calls
     */
    public function unverifiedDenial(string $reply, array $toolCalls): ?string
    {
        if ($toolCalls !== [] || preg_match(self::DENIAL, $reply, $match) !== 1) {
            return null;
        }

        return $match[0];
    }

    public function denialCorrection(string $denial): string
    {
        return sprintf(self::DENIAL_CORRECTION, $denial);
    }

    /**
     * The completed action a reply states about a document, when the turn ran no tool to back it; null when it
     * states none, names no document, or a tool ran.
     *
     * @param list<array<string, mixed>> $toolCalls the turn's tool calls
     */
    public function unverifiedCompletion(string $reply, array $toolCalls): ?string
    {
        if ($toolCalls !== [] || preg_match(self::DOCUMENT, $reply) !== 1 || preg_match(self::COMPLETION, $reply, $match) !== 1) {
            return null;
        }

        return $match[0];
    }

    /**
     * What a no-tool turn asserts about the state of the books, either way: a denial of earlier work or a completion
     * nothing performed. One correction round and one flag serve both; the texts differ.
     *
     * @param list<array<string, mixed>> $toolCalls the turn's tool calls
     */
    public function unverifiedStateClaim(string $reply, array $toolCalls): ?string
    {
        return $this->unverifiedDenial($reply, $toolCalls) ?? $this->unverifiedCompletion($reply, $toolCalls);
    }

    public function stateClaimCorrection(string $claim): string
    {
        return preg_match(self::DENIAL, $claim) === 1
            ? $this->denialCorrection($claim)
            : sprintf(self::COMPLETION_CORRECTION, $claim);
    }

    public function stateClaimWarning(string $claim): string
    {
        return preg_match(self::DENIAL, $claim) === 1 ? self::DENIAL_WARNING : self::COMPLETION_WARNING;
    }
}
