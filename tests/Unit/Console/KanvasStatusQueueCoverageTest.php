<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\KanvasStatusCommand;
use ReflectionClass;
use Tests\TestCaseUnit;

/**
 * Coverage ratchet: every queue a worker consumes must show up in `kanvas:status`.
 *
 * A queue with a worker but no row in the status table is invisible — it can back up or
 * accumulate failures for weeks with `kanvas:status` still printing "ALL GOOD". Adding a
 * worker to the compose files without adding the queue here fails CI instead.
 */
final class KanvasStatusQueueCoverageTest extends TestCaseUnit
{
    private const COMPOSE_FILES = [
        'docker-compose.yml',
        'docker-compose.development.yml',
        'docker-compose.1.x.yml',
    ];

    public function testEveryComposeQueueIsReportedByStatusCommand(): void
    {
        $reported = new ReflectionClass(KanvasStatusCommand::class)->getConstant('QUEUES');

        $missing = array_values(array_diff($this->composeQueues(), $reported));

        $this->assertSame(
            [],
            $missing,
            'Queues have workers but are not reported by kanvas:status: ' . implode(', ', $missing)
        );
    }

    /**
     * @return list<string>
     */
    private function composeQueues(): array
    {
        $root = dirname(__DIR__, 3);
        $queues = [];

        foreach (self::COMPOSE_FILES as $file) {
            $contents = file_get_contents($root . '/' . $file);
            $this->assertIsString($contents, "Missing compose file {$file}");

            preg_match_all('/--queue=([a-zA-Z0-9_,-]+)/', $contents, $matches);

            foreach ($matches[1] as $value) {
                foreach (explode(',', $value) as $queue) {
                    $queues[] = $queue;
                }
            }
        }

        return array_values(array_unique($queues));
    }
}
