<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Throwable;

/**
 * Chases an unanswered intake, and eventually drops it.
 *
 * A plan parked in INTAKE because nobody replied is worse than no plan at all — it looks like work in
 * progress. So it gets nudged, and after a set number of unanswered rounds it is cancelled with a
 * reason rather than left to rot.
 *
 * Anti-spam is the same shape `NudgeInactivePlanAction` uses and for the same reason its comment
 * gives ("we've been bitten before"): one nudge per window, tracked on the plan itself. It is tracked
 * in `input` rather than a ledger lookup because an intake plan may have no project, and the ledger
 * query there keys off one.
 */
class ChaseStaleIntakeAction
{
    public const string RESULT_NOT_STALE = 'not_stale';
    public const string RESULT_RECENTLY_CHASED = 'recently_chased';
    public const string RESULT_CHASED = 'chased';
    public const string RESULT_CANCELLED = 'cancelled';

    /** Unanswered rounds before the plan is dropped. Three chases is enough to be sure nobody is coming. */
    public const int MAX_CHASES = 3;

    public function __construct(
        private readonly Plan $plan,
        private readonly int $staleAfterHours = 24,
        private readonly bool $force = false,
    ) {
    }

    public function execute(): string
    {
        if ($this->plan->status !== PlanStatusEnum::INTAKE->value) {
            return self::RESULT_NOT_STALE;
        }

        /** @var array<string, mixed> $input */
        $input = is_array($this->plan->input) ? $this->plan->input : [];
        $chases = (int) ($input['intake_chases'] ?? 0);

        if (! $this->force && ! $this->isDue($input)) {
            // Never chased and still inside the window, or chased recently. Both are "leave it alone",
            // but they are different states to a human reading a sweep report.
            return $chases > 0 ? self::RESULT_RECENTLY_CHASED : self::RESULT_NOT_STALE;
        }

        if ($chases >= self::MAX_CHASES) {
            return $this->cancel($input, $chases);
        }

        return $this->chase($input, $chases + 1);
    }

    /**
     * Measured from the last chase, falling back to creation — deliberately NOT from `updated_at`.
     *
     * Chasing writes to the plan, so an updated_at clock would be reset by the very act of chasing:
     * a plan would be chased exactly once and then look permanently fresh, never reaching the
     * cancellation that makes "chase or drop" a rule rather than a hope.
     *
     * @param array<string, mixed> $input
     */
    private function isDue(array $input): bool
    {
        $since = $this->lastChasedAt($input) ?? $this->plan->created_at;

        return $since->lt(Carbon::now()->subHours($this->staleAfterHours));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function lastChasedAt(array $input): ?Carbon
    {
        $last = $input['intake_last_chased_at'] ?? null;

        if (! is_string($last) || $last === '') {
            return null;
        }

        try {
            return Carbon::parse($last);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function chase(array $input, int $chase): string
    {
        $this->plan->input = [
            ...$input,
            'intake_chases' => $chase,
            'intake_last_chased_at' => Carbon::now()->toIso8601String(),
        ];
        $this->plan->saveQuietly();

        $this->plan->emitLedgerEvent('plan.intake.chased', payload: [
            'chase' => $chase,
            'of' => self::MAX_CHASES,
            'outstanding_questions' => $input['outstanding_questions'] ?? [],
        ]);

        return self::RESULT_CHASED;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function cancel(array $input, int $chases): string
    {
        $this->plan->status = PlanStatusEnum::CANCELLED->value;
        $this->plan->error_message = sprintf(
            'Intake abandoned: asked %d time(s) over %d hours with no answer.',
            $chases,
            $this->staleAfterHours * $chases,
        );
        $this->plan->input = [...$input, 'intake_cancelled_at' => Carbon::now()->toIso8601String()];
        $this->plan->saveQuietly();

        $this->plan->emitLedgerEvent('plan.intake.abandoned', payload: [
            'chases' => $chases,
            'topic' => $this->plan->title,
        ]);

        return self::RESULT_CANCELLED;
    }
}
