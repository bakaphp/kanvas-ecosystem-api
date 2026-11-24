<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use NeuronAI\Router\RouterProvider;
use Override;
use Throwable;

final class KanvasRouterProvider extends RouterProvider
{
    /** Bedrock uses AWS SDK exceptions rather than Neuron's HttpException. */
    #[Override]
    protected function defaultFallbackStrategy(Throwable $e): bool
    {
        if (! $e instanceof BedrockRuntimeException) {
            return parent::defaultFallbackStrategy($e);
        }

        $status = $e->getStatusCode();

        return $e->isConnectionError() || $status === 429 || $status >= 500;
    }
}
