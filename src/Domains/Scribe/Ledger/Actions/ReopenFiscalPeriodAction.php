<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;

/**
 * Reopens a closed fiscal period back to OPEN.
 *
 * Should be rare in production — used for audit corrections, prior-year adjustments after late discoveries,
 * etc. The gate is intentionally permissive (no extra ability check beyond reaching this Action) — gate at
 * the mutation layer instead via Bouncer / role-based access.
 *
 * Idempotent: reopening an already-OPEN period is a no-op.
 */
class ReopenFiscalPeriodAction
{
    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly ?UserInterface $user = null,
        public readonly ?string $reopenNotes = null,
    ) {
    }

    public function execute(): FiscalPeriod
    {
        if ($this->period->status === FiscalPeriodStatusEnum::OPEN) {
            return $this->period;
        }

        return DB::connection('accounting')->transaction(function (): FiscalPeriod {
            $period = $this->period;
            $period->status = FiscalPeriodStatusEnum::OPEN;
            $period->closed_at = null;
            $period->closed_by_users_id = null;
            if ($this->reopenNotes !== null) {
                $existing = $period->close_notes;
                $period->close_notes = $existing !== null && $existing !== ''
                    ? $existing . "\n--- REOPENED: " . $this->reopenNotes
                    : 'REOPENED: ' . $this->reopenNotes;
            }
            $period->save();

            return $period->refresh();
        });
    }
}
