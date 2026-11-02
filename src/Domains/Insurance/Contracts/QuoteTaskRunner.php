<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

interface QuoteTaskRunner
{
    public function run(array $tasks, int $timeoutMs): array;
}
