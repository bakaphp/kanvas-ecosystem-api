<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;
use PHPUnit\Framework\TestCase;
use Tests\Traits\AssertsToolRunKeys;

/**
 * Every INTRAS tool budgets its runs per arguments, not per tool name.
 *
 * NeuronAI caps a tool at 10 runs per turn, counted per key, and the default key is the tool
 * name — so all calls to one tool share a single budget. These are cheap local reads over the
 * flat tables, and a real question legitimately makes several distinct calls: a year-over-year
 * comparison is two date ranges before any refinement, and a lookup the analyst half-remembers
 * costs a few attempts at the name.
 *
 * Without a per-input key the eleventh distinct call throws `ToolRunsExceededException`, which
 * kills the whole turn and surfaces to the user as "I kept retrying the same lookup without
 * getting anywhere" — with no indication that the tools were working and the budget was the
 * problem. That is what happened to a HELLOWELLNESS lookup: three reasonable attempts at the
 * name, the SIPGO code and the date, all charged to one budget.
 *
 * Keying by inputs keeps the loop protection — an identical call still caps at 10 — while
 * letting distinct work proceed.
 */
class IntrasToolRunBudgetTest extends TestCase
{
    use AssertsToolRunKeys;

    public function testEveryIntrasToolKeysItsRunBudgetByInputs(): void
    {
        $unkeyed = [];
        $checked = 0;

        foreach ($this->intrasTools() as $tool) {
            $checked++;

            if (! in_array(TrackByInputs::class, class_uses_recursive($tool), true)) {
                $unkeyed[] = $tool->getName();
            }
        }

        $this->assertGreaterThan(0, $checked, 'no INTRAS tools were discovered');
        $this->assertSame(
            [],
            $unkeyed,
            'Add `use TrackByInputs` to: ' . implode(', ', $unkeyed)
        );
    }

    public function testDistinctArgumentsGetDistinctBudgetsAndIdenticalOnesDoNot(): void
    {
        foreach ($this->intrasTools() as $tool) {
            if (! in_array(TrackByInputs::class, class_uses_recursive($tool), true)) {
                continue;
            }

            $this->assertRunKeyFollowsInputs($tool);
        }
    }

    /**
     * @return list<Tool>
     */
    private function intrasTools(): array
    {
        $tools = [];

        foreach (glob(dirname(__DIR__, 4) . '/src/Domains/Connectors/Intras/Neuron/Tools/*.php') ?: [] as $file) {
            $class = 'Kanvas\\Connectors\\Intras\\Neuron\\Tools\\' . basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Tool::class)) {
                $tools[] = new $class();
            }
        }

        return $tools;
    }
}
