<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Whether a plan still needs a human before it acts.
 *
 * Keyed on the tools a plan actually reaches for, not on its `plan_type`. What a task touched is a
 * fact; what it was labelled is a claim, and the labels are written by the same agent asking for
 * autonomy. A plan that quietly grew an outbound step keeps its gate under this rule and loses it
 * under a type-based one.
 *
 * Autonomy is the reward for a verification record, never the starting position — and three
 * categories never earn it however clean that record is: money, customers, and anything the agent
 * would be doing to itself.
 */
class ApprovalPolicy
{
    /**
     * Tool-name prefixes and ids that keep the gate permanently. Deliberately not configurable: a
     * policy a tenant can soften is a policy that gets softened on the day it first blocks something.
     *
     * @var list<string>
     */
    private const array ALWAYS_GATED = [
        // Anything that leaves the building.
        'send_',
        'create_message',
        'hand_off_lead',
        'write_google_sheet',
        'update_google_sheet_cell',
        'clear_google_sheet_range',

        // Money.
        'create_ap_bill',
        'create_ar_invoice',
        'create_ar_credit_memo',
        'void_',
        'approve_pending_item',
        'match_bills_for_payment',
        'match_invoices_for_payment',

        // The agent changing its own capabilities or commissioning code.
        'update_agent_instructions',
        'hire_agent',
        'dispatch_coding_task',
        'dispatch_long_task',
        'report_capability_gap',
    ];

    /**
     * @param list<string> $toolNames Tools the plan's work has used or intends to use.
     */
    public static function requiresApproval(Plan $plan, array $toolNames): bool
    {
        if (self::touchesGatedTool($toolNames)) {
            return true;
        }

        // Everything else needs a human until this plan has actually been checked once.
        return ! self::hasVerificationRecord($plan);
    }

    /**
     * @param list<string> $toolNames
     */
    public static function touchesGatedTool(array $toolNames): bool
    {
        foreach ($toolNames as $name) {
            foreach (self::ALWAYS_GATED as $gated) {
                if ($name === $gated || str_starts_with($name, $gated)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A recorded pass from `VerifyPlanAction`. Absence, or a failed check, both count as no record. */
    public static function hasVerificationRecord(Plan $plan): bool
    {
        $output = is_array($plan->output) ? $plan->output : [];
        $verification = $output['verification'] ?? null;

        return is_array($verification) && ($verification['verified'] ?? false) === true;
    }

    /**
     * @param list<string> $toolNames
     */
    public static function reason(Plan $plan, array $toolNames): ?string
    {
        if (self::touchesGatedTool($toolNames)) {
            return 'This plan touches money, a customer, or the agent\'s own capabilities. Those always '
                . 'need a human, whatever its track record.';
        }

        return self::hasVerificationRecord($plan)
            ? null
            : 'This plan has no verification record yet, so a human approves it before it acts.';
    }
}
