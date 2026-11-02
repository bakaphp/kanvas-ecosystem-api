<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Closure;
use Kanvas\Insurance\Contracts\QuoteTaskRunner;

class RecordingQuoteTaskRunner implements QuoteTaskRunner
{
    public array $batches = [];

    public array $timeouts = [];

    public function __construct(
        private readonly ?Closure $failsBatch = null,
    ) {
    }

    public function run(array $tasks, int $timeoutMs): array
    {
        $index = count($this->batches);
        $this->batches[] = array_keys($tasks);
        $this->timeouts[] = $timeoutMs;

        if ($this->failsBatch !== null) {
            ($this->failsBatch)($index);
        }

        return array_map(fn (callable $task): mixed => $task(), $tasks);
    }
}
