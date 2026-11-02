<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Closure;
use Kanvas\Insurance\Contracts\QuoteTaskRunner;
use Laravel\Octane\Facades\Octane;
use Swoole\Http\Server;

class OctaneQuoteTaskRunner implements QuoteTaskRunner
{
    public function run(array $tasks, int $timeoutMs): array
    {
        if ($tasks === []) {
            return [];
        }

        if (! app()->bound(Server::class)) {
            return array_map(fn (callable $task): mixed => $task(), $tasks);
        }

        return Octane::concurrently($this->asClosures($tasks), $timeoutMs);
    }

    private function asClosures(array $tasks): array
    {
        return array_map(fn (callable $task): Closure => static fn (): mixed => $task(), $tasks);
    }
}
