<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Services;

use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Exceptions\ValidationException;

class PassAttemptLimiter
{
    public const int MAX_FAILURES = 5;
    public const int DECAY_SECONDS = 900;

    public function __construct(
        private readonly string $scope,
        private readonly int $userId,
        private readonly ?string $ipAddress = null,
        private readonly int $maxFailures = self::MAX_FAILURES,
        private readonly int $decaySeconds = self::DECAY_SECONDS
    ) {
    }

    public function assertNotLimited(): void
    {
        foreach ($this->keys() as $key) {
            if (RateLimiter::tooManyAttempts($key, $this->maxFailures)) {
                throw new ValidationException('Too many invalid attempts. Try again later.');
            }
        }
    }

    public function recordFailure(): void
    {
        foreach ($this->keys() as $key) {
            RateLimiter::hit($key, $this->decaySeconds);
        }
    }

    private function keys(): array
    {
        $keys = [$this->scope . ':user:' . $this->userId];

        if ($this->ipAddress !== null) {
            $keys[] = $this->scope . ':ip:' . $this->ipAddress;
        }

        return $keys;
    }
}
