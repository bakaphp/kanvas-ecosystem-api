<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;

class TimeSlotBookingLock
{
    private const LOCK_SECONDS = 30;
    private const WAIT_SECONDS = 10;

    public static function key(int $timeSlotId): string
    {
        return 'event:time-slot-booking:' . $timeSlotId;
    }

    public static function run(array $timeSlotIds, Closure $callback, int $waitSeconds = self::WAIT_SECONDS): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $timeSlotIds)));
        sort($ids);

        return self::acquire($ids, $callback, $waitSeconds);
    }

    private static function acquire(array $remainingIds, Closure $callback, int $waitSeconds): mixed
    {
        if ($remainingIds === []) {
            return $callback();
        }

        $id = array_shift($remainingIds);

        try {
            return Cache::lock(self::key($id), self::LOCK_SECONDS)->block(
                $waitSeconds,
                fn () => self::acquire($remainingIds, $callback, $waitSeconds)
            );
        } catch (LockTimeoutException) {
            throw new ValidationException('This time slot is being booked by someone else. Please try again.');
        }
    }
}
