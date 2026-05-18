<?php

declare(strict_types=1);

namespace Kanvas\Health\Checks;

use Illuminate\Support\Facades\Queue;
use Override;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class QueueSizeCheck extends Check
{
    protected string $connection = 'redis';

    /** @var array<string, int> */
    protected array $thresholds = [];

    /** @var array<string, int> */
    protected array $warningThresholds = [];

    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * @param array<string, int> $thresholds queue name => max pending before failed
     */
    public function thresholds(array $thresholds): self
    {
        $this->thresholds = $thresholds;

        return $this;
    }

    /**
     * @param array<string, int> $thresholds queue name => max pending before warning
     */
    public function warnings(array $thresholds): self
    {
        $this->warningThresholds = $thresholds;

        return $this;
    }

    #[Override]
    public function run(): Result
    {
        $sizes = [];
        $failed = [];
        $warned = [];

        foreach ($this->thresholds as $queue => $max) {
            $size = Queue::connection($this->connection)->size($queue);
            $sizes[$queue] = $size;

            if ($size > $max) {
                $failed[] = "{$queue}={$size}/{$max}";

                continue;
            }

            $warnAt = $this->warningThresholds[$queue] ?? null;
            if ($warnAt !== null && $size > $warnAt) {
                $warned[] = "{$queue}={$size}/{$warnAt}";
            }
        }

        $result = Result::make()->meta($sizes);

        if ($failed !== []) {
            return $result
                ->shortSummary(implode(' ', $failed))
                ->failed('Queue backlog over threshold: ' . implode(', ', $failed));
        }

        if ($warned !== []) {
            return $result
                ->shortSummary(implode(' ', $warned))
                ->warning('Queue backlog elevated: ' . implode(', ', $warned));
        }

        return $result->shortSummary('ok')->ok();
    }
}
