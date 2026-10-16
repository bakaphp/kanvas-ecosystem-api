<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Enums;

/**
 * What one row of a flat table represents.
 *
 * The grain is the single most important property of a report table. A one-to-many child earns
 * its own grain only when more than one filter condition applies to the *same* child row —
 * otherwise it belongs in a JSON column on the parent. Getting this wrong is what makes
 * "attended a Seminario AND in Q1" match someone who did neither together.
 */
enum ReportGrainEnum: string
{
    case PERSON = 'person';
    case PERSON_EVENT = 'person_event';
    case COMPANY = 'company';
    case COMPANY_PLAN = 'company_plan';
    case COMPANY_PLAN_LINE = 'company_plan_line';
    case COMPANY_OFFICE = 'company_office';
    case EVENT_VERSION = 'event_version';
    case EVENT_COST = 'event_cost';
    case FACILITATOR = 'facilitator';
    case FACILITATOR_ASSIGNMENT = 'facilitator_assignment';
    case PASS = 'pass';
    case EVALUATION_ANSWER = 'evaluation_answer';
    case QUOTE = 'quote';

    /**
     * Human-readable, for `describe_report_model` — an agent reads this to know whether counting
     * rows answers the question or whether it needs COUNT(DISTINCT).
     */
    public function description(): string
    {
        return match ($this) {
            self::PERSON => 'One row per person.',
            self::PERSON_EVENT => 'One row per person per event registration. Counting people needs COUNT(DISTINCT).',
            self::COMPANY => 'One row per company.',
            self::COMPANY_PLAN => 'One row per plan a company holds.',
            self::COMPANY_PLAN_LINE => 'One row per line item within a company plan.',
            self::COMPANY_OFFICE => 'One row per company office.',
            self::EVENT_VERSION => 'One row per event version.',
            self::EVENT_COST => 'One row per cost line on an event version.',
            self::FACILITATOR => 'One row per facilitator.',
            self::FACILITATOR_ASSIGNMENT => 'One row per facilitator assigned to an event version.',
            self::PASS => 'One row per courtesy pass.',
            self::EVALUATION_ANSWER => 'One row per answer on a filled evaluation form. '
                . 'Counting rows counts answers — use COUNT(DISTINCT formulario_id) for forms '
                . 'and COUNT(DISTINCT peoples_id) for people.',
            self::QUOTE => 'One row per quote (proposal) a company requested.',
        };
    }
}
