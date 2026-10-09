<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Closure;
use Kanvas\Insurance\Contracts\QuoteTaskRunner;

class FakeQuoteTaskRunner implements QuoteTaskRunner
{
    public ?int $lastTimeoutMs = null;

    public function __construct(
        private readonly ?Closure $dropsKey = null,
        private readonly ?Closure $throwsFor = null,
    ) {
    }

    public function run(array $tasks, int $timeoutMs): array
    {
        $this->lastTimeoutMs = $timeoutMs;
        $results = [];

        if ($this->throwsFor !== null) {
            ($this->throwsFor)(array_keys($tasks));
        }

        foreach ($tasks as $key => $task) {
            if ($this->dropsKey !== null && ($this->dropsKey)($key)) {
                $results[$key] = false;

                continue;
            }

            $results[$key] = $task();
        }

        return $results;
    }
}
