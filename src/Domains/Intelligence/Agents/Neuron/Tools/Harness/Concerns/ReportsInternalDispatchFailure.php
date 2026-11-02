<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness\Concerns;

use Throwable;

/**
 * A dispatch failure the model cannot act on is reported to Sentry and summarised in one fixed
 * sentence, so no exception text, class or table name can be repeated to the person.
 */
trait ReportsInternalDispatchFailure
{
    public const string INTERNAL_FAILURE = 'The coding job could not be started right now because of an internal error on our side.';

    public const string INTERNAL_FAILURE_GUIDANCE = 'No coding job was started and nothing was changed. '
        . 'Tell the person it failed to start and that the team has been notified. Do NOT quote any '
        . 'error text, class or table name, do NOT retry in this turn, and do NOT write the code, the '
        . 'file contents or a diff in the conversation as a substitute.';

    /**
     * @return array<string, mixed>
     */
    private function internalFailure(Throwable $e, string $guidance = ''): array
    {
        report($e);

        return $this->failed(self::INTERNAL_FAILURE, guidance: trim(self::INTERNAL_FAILURE_GUIDANCE . ' ' . $guidance));
    }
}
