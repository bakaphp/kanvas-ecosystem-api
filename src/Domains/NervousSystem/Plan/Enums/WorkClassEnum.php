<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

/**
 * The kinds of work a brief can describe, and what each needs pinned down before anyone starts.
 *
 * A hardcoded enum rather than per-app config, deliberately: nobody knows yet what the real classes
 * are, and config-first would freeze today's guess into a schema people then have to migrate off.
 *
 * The required fields are the intake phase's stopping condition. That is the whole reason they are
 * enumerated rather than judged — "has the agent asked enough?" becomes a checklist rather than a
 * confidence score, which is the only version of the question that is testable.
 */
enum WorkClassEnum: string
{
    /** Changes to a repository we own. */
    case CODE = 'code';

    /** Work over Kanvas records — chase invoices, qualify leads, reconcile orders. */
    case DATA_OPERATION = 'data_operation';

    /** Something goes out to a customer: email, message, document. */
    case OUTBOUND = 'outbound';

    /** Read-and-report. The only class with no side effects, and the only cheap one to get wrong. */
    case RESEARCH = 'research';

    /**
     * Fields a brief of this class must carry before it can be dispatched.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::CODE => ['objective', 'repository', 'acceptance_criteria'],
            self::DATA_OPERATION => ['objective', 'entity_scope', 'success_measure'],
            self::OUTBOUND => ['objective', 'audience', 'approval_owner'],
            self::RESEARCH => ['objective', 'questions'],
        };
    }

    /**
     * The question to ask when a required field is missing. Phrased for a human, because the agent
     * is going to put it in front of one.
     */
    public function questionFor(string $field): string
    {
        return match ($field) {
            'objective' => 'What outcome counts as this being done?',
            'repository' => 'Which repository should this change land in?',
            'acceptance_criteria' => 'How will we know the change is correct — what should be true afterwards?',
            'entity_scope' => 'Which records does this cover — which company, which date range, which filter?',
            'success_measure' => 'What number tells us this worked?',
            'audience' => 'Who receives this, and how were they selected?',
            'approval_owner' => 'Who signs off before anything is sent?',
            'questions' => 'What specific questions should the answer cover?',
            default => sprintf('What should "%s" be?', str_replace('_', ' ', $field)),
        };
    }
}
