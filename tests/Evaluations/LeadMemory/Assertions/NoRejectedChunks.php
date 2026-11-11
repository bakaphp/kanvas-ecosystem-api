<?php

declare(strict_types=1);

namespace Tests\Evaluations\LeadMemory\Assertions;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Assertions\AbstractAssertion;

/**
 * A chunk the validator rejected twice was skipped, so the summary under evaluation is missing part of
 * the transcript; the other assertions would then judge a summary production never saves. The reasons
 * are the finding: they say what the model keeps getting wrong about the operations.
 */
final class NoRejectedChunks extends AbstractAssertion
{
    /**
     * @param list<list<string>> $actual the validator's reasons, one list per rejected chunk
     */
    public function evaluate(mixed $actual): AssertionResult
    {
        $chunks = (array) $actual;

        if ($chunks === []) {
            return AssertionResult::pass(1.0, 'every chunk was applied');
        }

        $reasons = implode(' | ', array_map(static fn (array $reasons): string => implode('; ', $reasons), $chunks));

        return AssertionResult::fail(0.0, count($chunks) . " chunk(s) rejected twice and skipped: {$reasons}");
    }
}
