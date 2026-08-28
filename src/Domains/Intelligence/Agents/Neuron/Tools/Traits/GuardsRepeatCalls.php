<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use stdClass;

/**
 * Stops the second identical call in a turn, and says why.
 *
 * NeuronAI's own budget caps a tool at `getMaxRuns()` executions and `TrackByInputs` keys that count
 * by arguments — but both only bound the waste. The model still gets nothing back that explains the
 * stop, so it keeps trying until the cap kills the whole turn. This returns the first call's own
 * result again, labelled, so the model can read what it already had.
 *
 * The mechanism is the one `ReadMessageContentTool` documents: NeuronAI clones the registered tool
 * for each call, so a stdClass created in the constructor is shared by every call of the turn while
 * staying scoped to this agent instance.
 *
 * Opt-in per tool, deliberately. A status poll or a job check is *supposed* to be callable twice with
 * the same arguments and get a different answer; only tools whose answer cannot change within a turn
 * should use this.
 */
trait GuardsRepeatCalls
{
    private ?stdClass $repeatLedger = null;

    /**
     * Run the tool's work, unless this exact call already ran this turn.
     *
     * @param array<string, mixed> $inputs The arguments that define "the same call".
     * @param callable(): array<string, mixed> $work
     * @return array<string, mixed>
     */
    protected function oncePerTurn(array $inputs, callable $work): array
    {
        $ledger = $this->repeatLedger();
        $key = $this->repeatKey($inputs);

        if (isset($ledger->results[$key])) {
            /** @var array<string, mixed> $previous */
            $previous = $ledger->results[$key];

            return [
                ...$previous,
                'outcome' => ToolOutcomeEnum::NOOP->value,
                'repeat_call' => true,
                'note' => 'You already called this tool with exactly these arguments earlier in this turn and '
                    . 'this is the same answer it gave you. It has not changed and will not change. Use it, or '
                    . 'do something different — do NOT call this again.',
            ];
        }

        $result = $work();
        $ledger->results[$key] = $result;

        return $result;
    }

    /**
     * Normalised so argument order and absent-vs-null cannot make one call look like two. Nested
     * values are included: two calls differing only deep in a payload are genuinely different calls.
     *
     * @param array<string, mixed> $inputs
     */
    private function repeatKey(array $inputs): string
    {
        $normalised = array_filter($inputs, static fn (mixed $value): bool => $value !== null && $value !== '');
        ksort($normalised);

        return sha1((string) json_encode($normalised));
    }

    private function repeatLedger(): stdClass
    {
        if ($this->repeatLedger === null) {
            $this->repeatLedger = new stdClass();
            $this->repeatLedger->results = [];
        }

        return $this->repeatLedger;
    }
}
