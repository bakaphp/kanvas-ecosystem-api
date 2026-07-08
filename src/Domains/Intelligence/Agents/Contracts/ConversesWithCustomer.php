<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * Marks a customer-facing / external agent — its conversation partner is a PROSPECT, not company
 * staff (the mirror of ConversesWithUser). The archetype boundary: an external agent speaks as a
 * consistent persona (name only, no internal ids) and its memory is prospect-isolated — continuity
 * within the lead is fine, but it must NEVER get company-wide cross-entity recall (read_my_ledger)
 * that could leak one prospect's data to another. Callers branch on this to pick the memory surface.
 */
interface ConversesWithCustomer
{
}
