<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Tests\TestCase;

class GuardsRepeatCallsTest extends TestCase
{
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
}

class RepeatGuardedProbe
{
    use GuardsRepeatCalls;

    public int $executions = 0;

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
