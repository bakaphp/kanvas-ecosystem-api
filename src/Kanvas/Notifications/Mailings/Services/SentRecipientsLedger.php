<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Services;

use Illuminate\Support\Facades\Redis;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;

class SentRecipientsLedger
{
    private const int TTL_SECONDS = 4 * 24 * 60 * 60;

    public function __construct(
        private readonly AppUserMailing $mailing,
    ) {
    }

    public function markSent(int $userId): void
    {
        Redis::sadd($this->key(), $userId);
        Redis::expire($this->key(), self::TTL_SECONDS);
    }

    public function sentAmong(array $userIds): array
    {
        $userIds = array_values($userIds);

        if ($userIds === []) {
            return [];
        }

        $flags = Redis::pipeline(function ($pipe) use ($userIds): void {
            foreach ($userIds as $userId) {
                $pipe->sismember($this->key(), $userId);
            }
        });

        return array_values(array_filter(
            $userIds,
            fn (int $userId, int $position): bool => (bool) $flags[$position],
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function key(): string
    {
        return 'app-user-mailing:' . $this->mailing->uuid . ':sent';
    }
}
