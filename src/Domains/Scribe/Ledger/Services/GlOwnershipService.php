<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\Ledger\Enums\ExternalGlSourceEnum;

/**
 * Answers one question: for this company, does Kanvas own the books, or does an ERP?
 *
 * Exactly one system is the book of record per company. An ERP like Acumatica imports its own released
 * batches — including AP-check, AR-payment and cash batches — as origin=EXTERNAL journal entries, so the
 * cash is ALREADY booked by the time any bank feed sees it. A feed that also posted a cash JE would
 * double-count, and dedupe couldn't catch it: the two external_ids have different shapes
 * ("{Module}-{BatchNbr}" vs a bank txn id), so both rows insert cleanly.
 *
 * Ingest paths that post JEs (the bank feed today) call kanvasOwnsGl() and refuse to run when it's false,
 * rather than degrading into a half-mode. One early return, not a branching design.
 *
 * @see docs/accounting/mercury-connector-plan.md §12.1
 */
class GlOwnershipService
{
    public function kanvasOwnsGl(CompanyInterface $company): bool
    {
        return $this->externalGlSource($company) === null;
    }

    /**
     * Which ERP owns this company's GL, if any. Null means Kanvas does.
     */
    public function externalGlSource(CompanyInterface $company): ?ExternalGlSourceEnum
    {
        foreach (ExternalGlSourceEnum::cases() as $source) {
            if ((bool) $company->get($source->value)) {
                return $source;
            }
        }

        return null;
    }
}
