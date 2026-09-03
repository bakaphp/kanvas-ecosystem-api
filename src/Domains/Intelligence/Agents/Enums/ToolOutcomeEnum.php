<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * What a tool call actually did, as distinct from whether it threw.
 *
 * The case that matters is NOOP. A tool that ran correctly and changed nothing returns something
 * that reads to a model as failure — a zero, an empty list, an "already done" — so it calls again
 * with identical arguments until the run budget kills the turn. That is a recurring production
 * failure, not a hypothetical: `SalesRevenueTool` names Sentry KANVAS-ECOSYSTEM-682 in its own
 * comment, `AssignNervousSystemPlanTool` short-circuits by hand to avoid it, and the Agents guide
 * documents the same shape for `find_*` tools returning `count: 0`.
 *
 * Nothing declares a return schema to a model — `output_schema` exists on the catalog row but is
 * never sent to any provider — so the outcome has to ride in the returned value as text the model
 * reads. `guidance()` is that text.
 */
enum ToolOutcomeEnum: string
{
    /** Did what was asked and something changed. */
    case OK = 'ok';

    /** Ran correctly, changed nothing, and calling again will not change that. */
    case NOOP = 'noop';

    /** The arguments were wrong in a way the model can fix. */
    case INVALID_ARGS = 'invalid_args';

    /** Scoped correctly, nothing matched. Distinct from NOOP: a different argument might match. */
    case NOT_FOUND = 'not_found';

    /** Refused on permission or policy grounds. Retrying as-is cannot succeed. */
    case DENIED = 'denied';

    /** Ran out of time. The same call might succeed later; a narrower one is likelier to. */
    case TIMEOUT = 'timeout';

    /** An upstream service failed. Not the model's fault and not fixable by rewording. */
    case PROVIDER_ERROR = 'provider_error';

    /**
     * The default sentence a model reads for this outcome, written as an instruction because that is
     * how it will be treated. Override per call site when a tool has something specific to add — the
     * point is that no tool has to invent the general case.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::OK => 'Done. Report the result.',
            self::NOOP => 'This is the complete and correct answer, and nothing changed. Calling again with '
                . 'the same arguments returns the same thing. Report it as-is and move on — do NOT retry.',
            self::INVALID_ARGS => 'The arguments were not usable. Fix them and call once more; do not repeat '
                . 'the same call unchanged.',
            self::NOT_FOUND => 'Nothing matched. Repeating this exact call will not find anything — either '
                . 'search differently, or tell the user it does not exist.',
            self::DENIED => 'You are not allowed to do this. Retrying cannot succeed. Tell the user what is '
                . 'blocked and who can unblock it.',
            self::TIMEOUT => 'This took too long and was stopped. If you try again, narrow it — a smaller '
                . 'range or fewer records.',
            self::PROVIDER_ERROR => 'An external service failed. This is not something you can fix by '
                . 'rewording. Say so plainly rather than retrying in a loop.',
        };
    }

    /** Whether calling again with identical arguments could plausibly produce a different result. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TIMEOUT, self::PROVIDER_ERROR => true,
            self::OK, self::NOOP, self::INVALID_ARGS, self::NOT_FOUND, self::DENIED => false,
        };
    }
}
