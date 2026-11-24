<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;

/**
 * A person's own follow-up instruction for one entity, run by the same engine as the stage config:
 * one channel, a fixed body, a cadence and an attempt cap. Stored as two custom fields so the hourly
 * fan-out finds the active ones by name alone: `follow_up_plan` holds the running plan and is deleted
 * when it ends, `follow_up_plan_last` keeps the ended one for the UI and the tools.
 */
final readonly class ManualFollowUpPlan
{
    public const string ACTIVE_FIELD = 'follow_up_plan';
    public const string ENDED_FIELD = 'follow_up_plan_last';

    public const string REASON_REPLIED = 'replied';
    public const string REASON_EXHAUSTED = 'exhausted';
    public const string REASON_CANCELLED = 'cancelled';
    public const string REASON_REPLACED = 'replaced';
    public const string REASON_HANDED_OFF = 'handed_off';

    public const int MAX_ATTEMPTS_CAP = 30;
    public const int MAX_BODY_LENGTH = 1000;

    public function __construct(
        public string $channel,
        public string $body,
        public int $intervalMinutes,
        public int $maxAttempts,
        public Carbon $startAt,
        public Carbon $setAt,
        public int $setByUsersId,
        public bool $stopOnReply = true,
        public bool $respectWorkHours = true,
        public ?Carbon $doneAt = null,
        public ?string $doneReason = null,
        public ?int $attemptsSent = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $setAt = DateHelper::tryParseCarbon($raw['set_at'] ?? null);
        $body = trim((string) ($raw['body'] ?? ''));
        $channel = trim((string) ($raw['channel'] ?? ''));

        if ($setAt === null || $body === '' || $channel === '') {
            return null;
        }

        return new self(
            channel: $channel,
            body: $body,
            intervalMinutes: max(1, (int) ($raw['interval_minutes'] ?? 1440)),
            maxAttempts: max(1, (int) ($raw['max_attempts'] ?? 1)),
            startAt: DateHelper::tryParseCarbon($raw['start_at'] ?? null) ?? $setAt,
            setAt: $setAt,
            setByUsersId: (int) ($raw['set_by_users_id'] ?? 0),
            stopOnReply: (bool) ($raw['stop_on_reply'] ?? true),
            respectWorkHours: (bool) ($raw['respect_work_hours'] ?? true),
            doneAt: DateHelper::tryParseCarbon($raw['done_at'] ?? null),
            doneReason: isset($raw['done_reason']) ? (string) $raw['done_reason'] : null,
            attemptsSent: isset($raw['attempts_sent']) ? (int) $raw['attempts_sent'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'body' => $this->body,
            'interval_minutes' => $this->intervalMinutes,
            'max_attempts' => $this->maxAttempts,
            'start_at' => $this->startAt->toIso8601String(),
            'set_at' => $this->setAt->toIso8601String(),
            'set_by_users_id' => $this->setByUsersId,
            'stop_on_reply' => $this->stopOnReply,
            'respect_work_hours' => $this->respectWorkHours,
            'done_at' => $this->doneAt?->toIso8601String(),
            'done_reason' => $this->doneReason,
            'attempts_sent' => $this->attemptsSent,
        ];
    }

    public function ended(string $reason, int $attemptsSent): self
    {
        return new self(
            channel: $this->channel,
            body: $this->body,
            intervalMinutes: $this->intervalMinutes,
            maxAttempts: $this->maxAttempts,
            startAt: $this->startAt,
            setAt: $this->setAt,
            setByUsersId: $this->setByUsersId,
            stopOnReply: $this->stopOnReply,
            respectWorkHours: $this->respectWorkHours,
            doneAt: Carbon::now(),
            doneReason: $reason,
            attemptsSent: $attemptsSent,
        );
    }

    public function isDone(): bool
    {
        return $this->doneAt !== null;
    }

    /**
     * The first touch goes out at `start_at`; each later one an interval after the previous touch.
     */
    public function nextDueAt(?Carbon $lastTouchAt): Carbon
    {
        if ($lastTouchAt === null) {
            return $this->startAt->copy();
        }

        $afterLast = $lastTouchAt->copy()->addMinutes($this->intervalMinutes);

        return $afterLast->greaterThan($this->startAt) ? $afterLast : $this->startAt->copy();
    }
}
