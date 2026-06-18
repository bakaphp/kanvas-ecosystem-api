<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Enums;

/**
 * Expense lifecycle:
 *
 *   DRAFT             → PENDING_APPROVAL | VOIDED
 *   PENDING_APPROVAL  → APPROVED         | REJECTED
 *   APPROVED          → VOIDED                            (JE posted; void posts reversal JE)
 *   REJECTED          → (terminal — submitter creates a new expense)
 *   VOIDED            → (terminal)
 *
 * JE posts on APPROVAL — the company hasn't recognized the cost until someone with authority approves.
 * This is the policy gate (plan §11.4).
 *
 * @see plan §7.1 status pattern
 * @see plan §11.4 — Juan's $500 hotel worked example
 */
enum ExpenseStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case VOIDED = 'voided';

    public function isTerminal(): bool
    {
        return $this === self::REJECTED || $this === self::VOIDED;
    }

    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    public function hitsTheBooks(): bool
    {
        // Drafts + pending + rejected haven't posted a JE — no impact on the books.
        return $this === self::APPROVED || $this === self::VOIDED;
    }
}
