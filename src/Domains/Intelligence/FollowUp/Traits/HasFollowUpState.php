<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Traits;

use Baka\Support\DateHelper;
use Illuminate\Support\Carbon;

/**
 * Read/write of the `follow_up_state` custom field. Consumer model MUST
 * `use HasCustomFields` (provides the `get()` / `set()` this trait depends on).
 */
trait HasFollowUpState
{
    public function getFollowUpStateCount(): int
    {
        return (int) ($this->followUpState()['count'] ?? 0);
    }

    public function getFollowUpLastAt(): ?Carbon
    {
        return DateHelper::tryParseCarbon($this->followUpState()['last_at'] ?? null);
    }

    /**
     * @return string[]
     */
    public function getFollowUpChannelsUsed(): array
    {
        return (array) ($this->followUpState()['channels_used'] ?? []);
    }

    public function getFollowUpLastTemplate(): ?string
    {
        return $this->followUpState()['last_template'] ?? null;
    }

    public function getFollowUpExhaustedAt(): ?Carbon
    {
        return DateHelper::tryParseCarbon($this->followUpState()['exhausted_at'] ?? null);
    }

    public function getFollowUpExhaustedReason(): ?string
    {
        return $this->followUpState()['exhausted_reason'] ?? null;
    }

    public function getFollowUpStageEnteredAt(): ?Carbon
    {
        return DateHelper::tryParseCarbon($this->followUpState()['stage_entered_at'] ?? null);
    }

    public function isFollowUpExhausted(): bool
    {
        return ! empty($this->followUpState()['exhausted_at']);
    }

    public function followUpSilenceMinutesSince(?Carbon $lastInboundAt): int
    {
        $reference = $this->getFollowUpLastAt() ?? $lastInboundAt;

        // Carbon ^3 returns float; abs() so a future reference doesn't yield negative.
        return $reference ? (int) abs(Carbon::now()->diffInMinutes($reference)) : PHP_INT_MAX;
    }

    /**
     * @param string[] $channelsUsed
     */
    public function bumpFollowUp(array $channelsUsed, ?string $template): void
    {
        $current = $this->followUpState();

        $this->set('follow_up_state', array_merge($current, [
            'count' => ((int) ($current['count'] ?? 0)) + 1,
            'last_at' => Carbon::now()->toIso8601String(),
            'channels_used' => $channelsUsed,
            'last_template' => $template,
        ]));
    }

    public function markFollowUpExhausted(string $reason): void
    {
        $current = $this->followUpState();

        $this->set('follow_up_state', array_merge($current, [
            'exhausted_at' => Carbon::now()->toIso8601String(),
            'exhausted_reason' => $reason,
        ]));
    }

    public function resetFollowUpState(): void
    {
        $this->set('follow_up_state', [
            'count' => 0,
            'channels_used' => [],
            'last_at' => null,
            'last_template' => null,
            'exhausted_at' => null,
            'exhausted_reason' => null,
            'stage_entered_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function resumeFollowUp(): void
    {
        $current = $this->followUpState();

        $this->set('follow_up_state', array_merge($current, [
            'count' => 0,
            'channels_used' => [],
            'last_at' => null,
            'last_template' => null,
            'exhausted_at' => null,
            'exhausted_reason' => null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpState(): array
    {
        $raw = $this->get('follow_up_state');

        return is_array($raw) ? $raw : [];
    }
}
