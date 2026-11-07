<?php

declare(strict_types=1);

namespace Tests\Communication\Concerns;

/**
 * For tests that overwrite the test app's own settings (its Meta secret, its verify token) and put
 * them back afterwards. The test app is the one a local integration runs on, and two processes
 * snapshotting and restoring the same setting leave one test's value behind. Group('serial') only
 * keeps CI from running them in parallel; this keeps a plain `paratest` run from doing it.
 */
trait RefusesParallelRuns
{
    protected function refuseParallelRun(): void
    {
        if (getenv('PARATEST') !== false) {
            $this->markTestSkipped('Writes the test app\'s settings: run it with phpunit --group=serial.');
        }
    }
}
