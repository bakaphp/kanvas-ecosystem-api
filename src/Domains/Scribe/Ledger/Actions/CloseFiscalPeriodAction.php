<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use RuntimeException;

/**
 * Closes a fiscal period. Two modes:
 *
 *   - SOFT_CLOSED (default): blocks JE posting from normal users; bouncer-`accounting.post_to_closed_period`
 *     holders can still post (audit adjustments, late-arriving vendor bills, etc.). Reversible via Reopen.
 *
 *   - HARD_CLOSED: blocks JE posting unconditionally. Used for "year locked" after audit / tax filing.
 *     Reversible via Reopen, but the act is intentionally cumbersome (you have to call Reopen explicitly).
 *
 * Idempotent: closing an already-soft-closed period to SOFT_CLOSED is a no-op (returns the period unchanged).
 * Refuses to "weaken" from HARD_CLOSED → SOFT_CLOSED — use Reopen to go back to OPEN first.
 */
class CloseFiscalPeriodAction
{
    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly FiscalPeriodStatusEnum $targetStatus = FiscalPeriodStatusEnum::SOFT_CLOSED,
        public readonly ?UserInterface $user = null,
        public readonly ?string $closeNotes = null,
    ) {
    }

    public function execute(): FiscalPeriod
    {
        if ($this->targetStatus === FiscalPeriodStatusEnum::OPEN) {
            throw new RuntimeException(
                'CloseFiscalPeriodAction cannot target OPEN — use ReopenFiscalPeriodAction instead.'
            );
        }

        if ($this->period->status === $this->targetStatus) {
            return $this->period;
        }

        if ($this->period->status === FiscalPeriodStatusEnum::HARD_CLOSED
            && $this->targetStatus === FiscalPeriodStatusEnum::SOFT_CLOSED) {
            throw new RuntimeException(
                "Cannot weaken fiscal period {$this->period->id} from HARD_CLOSED to SOFT_CLOSED. "
                . 'Reopen to OPEN first, then re-close to the desired level.'
            );
        }

        return DB::connection('accounting')->transaction(function (): FiscalPeriod {
            $period = $this->period;
            $period->status = $this->targetStatus;
            $period->closed_at = Carbon::now();
            $period->closed_by_users_id = $this->user?->getId();
            if ($this->closeNotes !== null) {
                $period->close_notes = $this->closeNotes;
            }
            $period->save();

            return $period->refresh();
        });
    }
}
