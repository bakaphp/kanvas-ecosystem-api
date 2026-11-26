<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Kanvas\Intelligence\Agents\Services\LlmHttpRetryService;
use NeuronAI\Router\RouterProvider;
use Override;
use Throwable;

final class KanvasRouterProvider extends RouterProvider
{
    /**
     * Bedrock uses AWS SDK exceptions rather than Neuron's HttpException. A 408 is its
     * ModelTimeoutException: the model had no capacity for the request, which the SDK does not retry
     * and a second model answers at once (KANVAS-ECOSYSTEM-70B).
     */
    #[Override]
    protected function defaultFallbackStrategy(Throwable $e): bool
    {
        if (! $e instanceof BedrockRuntimeException) {
            return parent::defaultFallbackStrategy($e);
        }

        $status = $e->getStatusCode();

        return $e->isConnectionError()
            || ($status !== null && LlmHttpRetryService::isTransientStatus($status));
    }
}
