<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class GuardsRepeatCallsTest extends TestCase
{
    private const string TOOLS_PATH = 'src/Domains/Intelligence/Agents/Neuron/Tools';

    public function test_the_second_identical_call_does_not_execute_the_work(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $tool->run(['name' => 'acme']);

        $this->assertSame(1, $tool->executions);
    }

    /** The stop has to teach — a silent cache is what the run budget already does badly. */
    public function test_the_repeat_tells_the_model_the_answer_will_not_change(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $second = $tool->run(['name' => 'acme']);

        $this->assertTrue($second['repeat_call']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $second['outcome']);
        $this->assertStringContainsString('do NOT call this again', $second['note']);
    }

    /** The first call's answer is returned again, not replaced by an error. */
    public function test_the_repeat_still_carries_the_original_result(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $second = $tool->run(['name' => 'acme']);

        $this->assertSame('acme', $second['found']);
    }

    public function test_different_arguments_run_again(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme']);
        $tool->run(['name' => 'globex']);

        $this->assertSame(2, $tool->executions);
    }

    /** Argument order and empty-vs-absent must not make one call look like two. */
    public function test_argument_order_and_empty_values_do_not_defeat_the_guard(): void
    {
        $tool = new RepeatGuardedProbe();

        $tool->run(['name' => 'acme', 'limit' => 5]);
        $tool->run(['limit' => 5, 'name' => 'acme', 'note' => '']);

        $this->assertSame(1, $tool->executions);
    }

    /**
     * The whole guard hangs on this. NeuronAI never invokes the registered tool — it hands each call a
     * shallow `clone` (`HandleWithTools::findTool`), which shares the ledger OBJECT but would copy a
     * null. A ledger built lazily is therefore built on the clone, every call gets its own empty one,
     * and the guard silently never fires. That is how it shipped, unused, before KANVAS-ECOSYSTEM-6A1.
     */
    public function test_the_guard_still_holds_across_the_clones_neuron_makes_per_call(): void
    {
        $registered = new RepeatGuardedProbe();

        $firstCall = clone $registered;
        $secondCall = clone $registered;

        $firstCall->run(['name' => 'acme']);
        $second = $secondCall->run(['name' => 'acme']);

        $this->assertArrayHasKey('repeat_call', $second, 'The ledger did not survive the clone.');
        $this->assertSame(0, $secondCall->executions, 'The second clone re-ran the work.');
    }

    public function test_every_guarded_tool_initialises_its_ledger_in_the_constructor(): void
    {
        $guarded = $this->guardedTools();

        $this->assertNotEmpty($guarded, 'No tool uses GuardsRepeatCalls — did the trait lose its last caller?');

        foreach ($guarded as $class) {
            $constructor = new ReflectionClass($class)->getConstructor();

            $this->assertNotNull($constructor, $class . ' uses GuardsRepeatCalls but has no constructor to init it.');
            $this->assertSame(
                0,
                $constructor->getNumberOfRequiredParameters(),
                $class . ' takes constructor arguments; add it to this test explicitly so the guard stays covered.',
            );

            $tool = new $class();

            $this->assertTrue(
                new ReflectionProperty($tool, 'repeatLedger')->isInitialized($tool),
                $class . ' must call initRepeatGuard() in its constructor — see '
                    . 'test_the_guard_still_holds_across_the_clones_neuron_makes_per_call for why it cannot be lazy.',
            );
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function guardedTools(): array
    {
        $files = new Finder()
            ->files()
            ->in(base_path(self::TOOLS_PATH))
            ->name('*.php')
            ->notPath('Traits')
            ->contains('use GuardsRepeatCalls;');

        return array_values(array_map(
            static fn (SplFileInfo $file): string => 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\'
                . str_replace('/', '\\', $file->getRelativePath())
                . ($file->getRelativePath() === '' ? '' : '\\')
                . $file->getBasename('.php'),
            iterator_to_array($files),
        ));
    }
}

class RepeatGuardedProbe
{
    use GuardsRepeatCalls;

    public int $executions = 0;

    /**
     * Models the real contract: a guarded tool builds its ledger in the constructor, because that is
     * the only point that runs before NeuronAI starts cloning it per call.
     */
    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function run(array $inputs): array
    {
        return $this->oncePerTurn($inputs, function () use ($inputs): array {
            $this->executions++;

            return ['found' => $inputs['name'] ?? null];
        });
    }
}
