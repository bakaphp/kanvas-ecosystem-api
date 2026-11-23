<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

use Kanvas\Companies\Models\Companies;

/**
 * What "one turn" means for a given channel. Every value here is a channel policy decision — an
 * album of photos on WhatsApp, two SMS seconds apart, a Slack thread — while the machinery that
 * applies them is shared.
 */
final readonly class BurstPolicy
{
    /**
     * @param list<string> $correlationKeys what this burst answers to, most specific first. An
     *                                      album id before its sender, a thread before its speaker:
     *                                      the specific key binds parts that share an id, the broad
     *                                      one lets a straggler rejoin after the specific key has
     *                                      closed.
     * @param int $chainIdleSeconds how long a message extends the burst it joins
     * @param int $closeIdleSeconds how long to wait for silence before closing it. Separate from
     *                              the chain window so a channel can close early on a signal
     *                              (a mention, a question mark) without narrowing what still chains.
     * @param int $maxSeconds hard ceiling, so a conversation that never goes quiet still flushes
     * @param int $jitterSeconds random seconds ADDED to the close delay, so replies are not a
     *                           metronome. Additive rather than a percentage: scaling could shorten
     *                           the window below the point where a burst still collapses into one turn.
     */
    public function __construct(
        public array $correlationKeys,
        public int $chainIdleSeconds,
        public int $closeIdleSeconds,
        public int $maxSeconds,
        public int $jitterSeconds = 0,
    ) {
    }

    /**
     * One contact's burst, timed by the company's `{prefix}_burst_{idle,max,jitter}_seconds`
     * settings, where the idle window is both the chain and the close wait. The defaults are
     * WhatsApp's: jitter is there because the provider restricts numbers that answer like a
     * metronome, so a medium without that pressure passes 0.
     *
     * @param list<string> $correlationKeys
     */
    public static function fromCompanySettings(
        Companies $company,
        string $prefix,
        array $correlationKeys,
        int $idleSeconds = 30,
        int $maxSeconds = 180,
        int $jitterSeconds = 12,
    ): self {
        $setting = static fn (string $name, int $default): int => (int) ($company->get($prefix . '_burst_' . $name . '_seconds') ?? $default);
        $idle = $setting('idle', $idleSeconds);

        return new self(
            correlationKeys: $correlationKeys,
            chainIdleSeconds: $idle,
            closeIdleSeconds: $idle,
            maxSeconds: $setting('max', $maxSeconds),
            jitterSeconds: $setting('jitter', $jitterSeconds),
        );
    }

    public function closeDelaySeconds(): int
    {
        return $this->closeIdleSeconds + $this->jitter();
    }

    /**
     * The token has to outlive the longest burst plus the delay that closes it, or a flush job
     * wakes up to a key that expired underneath it and the burst is silently dropped.
     */
    public function tokenTtlSeconds(): int
    {
        return $this->maxSeconds + $this->closeIdleSeconds + $this->jitterSeconds;
    }

    private function jitter(): int
    {
        return $this->jitterSeconds > 0 ? random_int(0, $this->jitterSeconds) : 0;
    }
}
