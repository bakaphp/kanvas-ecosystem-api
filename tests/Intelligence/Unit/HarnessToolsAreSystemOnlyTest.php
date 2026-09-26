<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit;

use FilesystemIterator;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use NeuronAI\Tools\Tool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCaseUnit;

/**
 * Coverage ratchet: every coding-harness tool is marked system-only.
 *
 * These tools run code on a machine we operate, using a git token whose reach is the agent's reach,
 * and they push branches and open pull requests. `RequiresSystemAgent` is what keeps them off a
 * customer-facing agent, in both `MergesRegisteredTools` and `SetAgentToolAction`.
 *
 * The marker is an interface on each class, so tool #18 can be written without it and nothing at
 * runtime would say so — the tool would simply be grantable to an agent talking to a prospect. This
 * test is the thing that says so.
 */
final class HarnessToolsAreSystemOnlyTest extends TestCaseUnit
{
    public function testEveryHarnessToolRequiresASystemAgent(): void
    {
        $unmarked = [];
        $seen = 0;

        $directory = base_path('src/Domains/Intelligence/Agents/Neuron/Tools/Harness');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Harness\\' . $file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // Concerns/ holds shared traits, not tools.
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(\NeuronAI\Tools\Tool::class)) {
                continue;
            }

            $seen++;

            if (! $reflection->implementsInterface(RequiresSystemAgent::class)) {
                $unmarked[] = $reflection->getShortName();
            }
        }

        sort($unmarked);

        $this->assertGreaterThan(10, $seen, 'Expected to find the harness tools; the path may have moved.');
        $this->assertSame(
            [],
            $unmarked,
            'Every tool under Tools/Harness must implement RequiresSystemAgent — they execute code with '
            . "the agent's git token and must never reach a customer-facing agent:\n - "
            . implode("\n - ", $unmarked)
        );
    }
}
