<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

use Kanvas\Companies\Models\Companies;

/**
 * How long inbound SMS from one number is collected before the agent answers it as one turn.
 * Company-scoped rather than receiver-scoped, matching where the connector's other SMS knobs live.
 */
enum BurstConfigEnum: string
{
    case BURST_IDLE_SECONDS = 'twilio_burst_idle_seconds';
    case BURST_MAX_SECONDS = 'twilio_burst_max_seconds';

    /**
     * Off by default. Jitter exists on WhatsApp because the provider restricts numbers that answer
     * like a metronome; SMS has no such pressure, and the delay is already user-visible latency.
     */
    case BURST_JITTER_SECONDS = 'twilio_burst_jitter_seconds';

    /**
     * Superseded by BURST_IDLE_SECONDS. Read as the idle fallback so a tenant that tuned the old
     * knob keeps their tuning instead of silently jumping to the new default.
     */
    private const string LEGACY_BATCH_DELAY = 'twilio_batch_delay_seconds';

    public function default(): int
    {
        return match ($this) {
            // Has to exceed the gap between two texts a person sends back to back; under that,
            // each one opens its own turn and the agent answers twice.
            self::BURST_IDLE_SECONDS => 15,
            self::BURST_MAX_SECONDS => 90,
            self::BURST_JITTER_SECONDS => 0,
        };
    }

    public function getInt(Companies $company): int
    {
        $value = $company->get($this->value);

        if ($value === null && $this === self::BURST_IDLE_SECONDS) {
            $value = $company->get(self::LEGACY_BATCH_DELAY);
        }

        return $value === null ? $this->default() : (int) $value;
    }
}
