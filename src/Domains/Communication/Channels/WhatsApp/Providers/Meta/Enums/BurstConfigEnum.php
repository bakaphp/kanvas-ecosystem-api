<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums;

use Kanvas\Companies\Models\Companies;

/**
 * How long inbound WhatsApp from one person is collected before the agent answers it as one turn.
 * Company settings, with WaSender's defaults: same medium, same habits.
 */
enum BurstConfigEnum: string
{
    case BURST_IDLE_SECONDS = 'meta_whatsapp_burst_idle_seconds';
    case BURST_MAX_SECONDS = 'meta_whatsapp_burst_max_seconds';

    /**
     * WhatsApp restricts numbers that answer like a metronome.
     */
    case BURST_JITTER_SECONDS = 'meta_whatsapp_burst_jitter_seconds';

    public function default(): int
    {
        return match ($this) {
            self::BURST_IDLE_SECONDS => 30,
            self::BURST_MAX_SECONDS => 180,
            self::BURST_JITTER_SECONDS => 12,
        };
    }

    public function getInt(Companies $company): int
    {
        $value = $company->get($this->value);

        return $value === null ? $this->default() : (int) $value;
    }
}
