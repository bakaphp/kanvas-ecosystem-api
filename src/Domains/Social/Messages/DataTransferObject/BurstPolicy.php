<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\DataTransferObject;

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
