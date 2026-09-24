<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Kanvas\Workflow\Contracts\SilentWorkflowException;

/**
 * ShouldntReport covers the lanes SilentWorkflowException cannot: the marker is only honoured by
 * KanvasActivity::executeIntegration, so a skip raised from a queued job, a GraphQL mutation or an
 * explicit report($e) would still reach Sentry. Handler::shouldntReport() checks ShouldntReport
 * before $dontReport, so both the report() helper and the unhandled-exception path stay quiet.
 */
class AgentReplySkippedException extends Exception implements ShouldntReport, SilentWorkflowException
{
    /**
     * What a human waiting on a synchronous turn is told. The internal message carries the agent id
     * and is not safe to surface, so every synchronous surface translates the skip to this instead.
     */
    public const string DEACTIVATED_USER_MESSAGE = 'This agent is deactivated and is not answering messages.';
}
