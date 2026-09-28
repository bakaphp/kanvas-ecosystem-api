<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Contracts;

/**
 * Lets a structured-output agent expand its own payload server-side after the
 * model replies.
 *
 * The point is to keep large, deterministic data out of the model's output. An
 * agent declares a minimal schema (ids, scores, short strings), and rebuilds the
 * full payload here from the DB — instead of spending thousands of output tokens
 * re-emitting rows it was just handed by a tool.
 */
interface TransformsStructuredOutput
{
    /**
     * @param array<string, mixed> $structured the model's raw structured output
     *
     * @return array<string, mixed> the payload sent to the caller
     */
    public function transformStructuredOutput(array $structured): array;
}
