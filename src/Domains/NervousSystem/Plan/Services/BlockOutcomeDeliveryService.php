<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Services;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * One message when a handed-off plan blocks, whichever of two queued jobs gets there first.
 *
 * The agent that handed the work off is woken to say it is blocked in its own words, and the blocked
 * alert is queued 45 seconds after the block as the deterministic fallback. Without a shared record
 * the person gets both, or, when the agent's turn is deferred, fails or says nothing, neither.
 *
 * Each block is a cycle with its own id, carried by both jobs. Everything here is a no-op for an id
 * that is no longer the plan's current cycle, so a job left over from an earlier block cannot release,
 * satisfy or answer a newer one. Queue coordination only: it is not an exactly-once guarantee across a
 * crash after a write.
 */
class BlockOutcomeDeliveryService
{
    private const string OPEN = 'open';
    private const string PENDING = 'pending';
    private const string DELIVERED = 'delivered';

    private const int RECORD_HOURS = 24;
    private const int PENDING_MINUTES = 60;
    private const int STATE_LOCK_SECONDS = 10;
    private const int DELIVERY_LOCK_SECONDS = 60;
    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly Plan $plan)
    {
    }

    public function startCycle(): string
    {
        return $this->locked(function (): string {
            $id = (string) Str::uuid();
            $this->write(['id' => $id, 'state' => self::OPEN, 'deadline' => null]);

            return $id;
        });
    }

    public function currentCycleId(): ?string
    {
        return $this->read()['id'] ?? null;
    }

    /**
     * The handing-off agent is about to answer, so the fallback waits for it. False when the cycle is
     * not current or was already answered: a reservation never reopens a delivered block.
     */
    public function reserveForCreator(string $cycleId): bool
    {
        return $this->locked(function () use ($cycleId): bool {
            $cycle = $this->read();

            if (($cycle['id'] ?? null) !== $cycleId || $cycle['state'] === self::DELIVERED) {
                return false;
            }

            // Replayed outcomes must not move the fallback's deadline.
            if ($cycle['state'] === self::PENDING) {
                return true;
            }

            $this->write([
                ...$cycle,
                'state' => self::PENDING,
                'deadline' => Carbon::now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
            ]);

            return true;
        });
    }

    public function releaseReservation(string $cycleId): void
    {
        $this->locked(function () use ($cycleId): void {
            $cycle = $this->read();

            if (($cycle['id'] ?? null) === $cycleId && $cycle['state'] === self::PENDING) {
                $this->write([...$cycle, 'state' => self::OPEN, 'deadline' => null]);
            }
        });
    }

    /**
     * Still the block this job was queued for: same cycle, and the plan has not moved on.
     */
    public function isCurrent(string $cycleId): bool
    {
        return $this->currentCycleId() === $cycleId
            && $this->plan->fresh()?->status === PlanStatusEnum::BLOCKED->value;
    }

    public function isAwaitingAnAnswer(string $cycleId): bool
    {
        return $this->isCurrent($cycleId) && ! $this->wasDelivered($cycleId);
    }

    public function wasDelivered(string $cycleId): bool
    {
        $cycle = $this->read();

        return ($cycle['id'] ?? null) === $cycleId && $cycle['state'] === self::DELIVERED;
    }

    /**
     * The agent still has the floor. Past its deadline it has lost it, reserved or not.
     */
    public function creatorIsPending(string $cycleId): bool
    {
        $cycle = $this->read();

        return ($cycle['id'] ?? null) === $cycleId
            && $cycle['state'] === self::PENDING
            && (int) $cycle['deadline'] > Carbon::now()->getTimestamp();
    }

    /**
     * Say it, once. $deliver posts the message and answers whether anything was actually posted; the
     * lock is held for the write only, never for a model turn. A posting exception leaves the cycle
     * undelivered, so a retry or the other job can still say it.
     *
     * @param Closure(): bool $deliver
     */
    public function claimDelivery(string $cycleId, bool $asCreator, Closure $deliver): bool
    {
        return (bool) Cache::lock($this->key() . ':delivery', self::DELIVERY_LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function () use ($cycleId, $asCreator, $deliver): bool {
                if (! $this->isAwaitingAnAnswer($cycleId)) {
                    return false;
                }

                // The fallback never talks over an agent that still has the floor.
                if (! $asCreator && $this->creatorIsPending($cycleId)) {
                    return false;
                }

                if (! $deliver()) {
                    return false;
                }

                $this->locked(function () use ($cycleId): void {
                    $cycle = $this->read();

                    // Posting may overlap a new block; only finish the cycle this job answered.
                    if (($cycle['id'] ?? null) !== $cycleId) {
                        return;
                    }

                    $this->write([...$cycle, 'state' => self::DELIVERED, 'deadline' => null]);
                });

                return true;
            },
        );
    }

    /**
     * @return array{id?: string, state?: string, deadline?: int|null}
     */
    private function read(): array
    {
        $cycle = Cache::get($this->key());

        return is_array($cycle) ? $cycle : [];
    }

    /**
     * @param array{id: string, state: string, deadline: int|null} $cycle
     */
    private function write(array $cycle): void
    {
        Cache::put($this->key(), $cycle, Carbon::now()->addHours(self::RECORD_HOURS));
    }

    private function locked(Closure $work): mixed
    {
        return Cache::lock($this->key() . ':state', self::STATE_LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $work);
    }

    private function key(): string
    {
        return sprintf(
            'ns:plan:%d:%d:%d:block-cycle',
            $this->plan->apps_id,
            $this->plan->companies_id,
            $this->plan->getId(),
        );
    }
}
