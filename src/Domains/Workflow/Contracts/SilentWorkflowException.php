<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Contracts;

/**
 * Marker for exceptions that represent an expected control-flow skip inside a workflow activity
 * (e.g. "AI mode is off for this lead", "message already responded") rather than a real fault.
 *
 * `KanvasActivity::executeIntegration()` still records the activity as FAILED in integration history
 * but does NOT report these to Sentry — they are business conditions, not errors, and reporting them
 * only drowns genuine failures in noise.
 */
interface SilentWorkflowException
{
}
