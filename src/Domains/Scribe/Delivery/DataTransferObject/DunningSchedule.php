<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\DataTransferObject;

use Kanvas\Companies\Models\Companies;

/**
 * A company's collections cadence (plan 3.1), the invoice's equivalent of a pipeline stage's
 * `follow_up` block: an invoice has no stages, its clock is the due date. Each step is a day offset
 * from the due date (negative = before it); after the last one and `escalate_after_days` past due the
 * account owner is brought in.
 *
 * Stored on the company as `scribe_dunning_schedule`; `scribe:setup-document-actions` seeds the
 * defaults, and a company without the setting still runs on them.
 */
final class DunningSchedule
{
    public const string SETTING = 'scribe_dunning_schedule';

    public const array DEFAULT_STEPS = [-3, 0, 7, 15, 30];
    public const int DEFAULT_ESCALATE_AFTER_DAYS = 30;
    public const int DEFAULT_REPLY_PAUSE_DAYS = 7;

    /**
     * @param list<int> $steps ascending day offsets from the due date
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $steps,
        public readonly int $escalateAfterDays,
        public readonly int $replyPauseDays,
    ) {
    }

    public static function forCompany(Companies $company): self
    {
        $raw = $company->get(self::SETTING);

        return self::fromArray(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $steps = array_values(array_unique(array_map(
            'intval',
            array_filter((array) ($raw['steps'] ?? self::DEFAULT_STEPS), 'is_numeric')
        )));
        sort($steps);

        return new self(
            enabled: (bool) ($raw['enabled'] ?? true),
            steps: $steps !== [] ? $steps : self::DEFAULT_STEPS,
            escalateAfterDays: (int) ($raw['escalate_after_days'] ?? self::DEFAULT_ESCALATE_AFTER_DAYS),
            replyPauseDays: max(0, (int) ($raw['reply_pause_days'] ?? self::DEFAULT_REPLY_PAUSE_DAYS)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return self::fromArray([])->toArray();
    }

    /**
     * @return bool whether the defaults were written; a cadence the company already set is left alone
     */
    public static function seedDefaults(Companies $company): bool
    {
        if (is_array($company->get(self::SETTING))) {
            return false;
        }

        $company->set(self::SETTING, self::defaults());

        return true;
    }

    /**
     * The stored setting shape, the one fromArray() reads back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'steps' => $this->steps,
            'escalate_after_days' => $this->escalateAfterDays,
            'reply_pause_days' => $this->replyPauseDays,
        ];
    }

    /**
     * The latest step that is due and later than the last one sent. Steps passed while nothing was
     * sent (an invoice sent late, a paused week) are skipped, so a customer never gets three
     * reminders on one morning.
     */
    public function dueStep(int $daysPastDue, ?int $lastSentStep): ?int
    {
        $due = null;
        foreach ($this->steps as $step) {
            if ($step <= $daysPastDue && ($lastSentStep === null || $step > $lastSentStep)) {
                $due = $step;
            }
        }

        return $due;
    }

    public function isLastStep(int $step): bool
    {
        return $step === max($this->steps);
    }

    public static function stepLabel(int $step): string
    {
        return 'dunning:' . ($step >= 0 ? '+' : '') . $step;
    }

    public static function stepFromLabel(?string $label): ?int
    {
        if ($label === null || preg_match('/^dunning:([+-]?\d+)$/', $label, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
