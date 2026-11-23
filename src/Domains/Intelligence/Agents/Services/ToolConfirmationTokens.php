<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\Agents\Enums\ToolConfirmationResultEnum;

final class ToolConfirmationTokens
{
    public const int TTL_MINUTES = 10;

    public function issue(
        string $threadId,
        string $turnId,
        int $userId,
        string $tool,
        array $arguments,
    ): string {
        $token = bin2hex(random_bytes(20));

        Cache::put(
            $this->key($threadId, $userId, $tool, $arguments),
            [
                'token' => $token,
                'turn_id' => $turnId,
                'issued_at' => now()->getTimestamp(),
            ],
            now()->addMinutes(self::TTL_MINUTES),
        );

        return $token;
    }

    public function consume(
        string $token,
        string $threadId,
        string $turnId,
        int $userId,
        string $tool,
        array $arguments,
    ): ToolConfirmationResultEnum {
        $key = $this->key($threadId, $userId, $tool, $arguments);
        $pending = Cache::get($key);

        if (! is_array($pending) || $this->expired($pending)) {
            Cache::forget($key);

            return ToolConfirmationResultEnum::NO_PENDING_PREVIEW;
        }

        if (! hash_equals((string) $pending['token'], $token)) {
            return ToolConfirmationResultEnum::TOKEN_MISMATCH;
        }

        if ($pending['turn_id'] === $turnId) {
            return ToolConfirmationResultEnum::SAME_TURN;
        }

        Cache::forget($key);

        return ToolConfirmationResultEnum::CONFIRMED;
    }

    private function expired(array $pending): bool
    {
        return now()->getTimestamp() - (int) $pending['issued_at'] > self::TTL_MINUTES * 60;
    }

    private function key(string $threadId, int $userId, string $tool, array $arguments): string
    {
        ksort($arguments);

        return 'tool-confirmation:' . hash('sha256', json_encode([$threadId, $userId, $tool, $arguments]));
    }
}
