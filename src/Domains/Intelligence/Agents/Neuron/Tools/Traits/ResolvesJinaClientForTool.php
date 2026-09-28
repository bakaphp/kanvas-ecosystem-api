<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\Jina\Client;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Build the app's Jina client from a tool's __invoke, or return a structured error the LLM can act on.
 *
 * Same contract as {@see ResolvesTavilyClientForTool}: the key is per-app, so a tool granted in a
 * workspace that never configured one would otherwise crash the chat on the client constructor's
 * ValidationException. Both failures are permanent for the turn, so the messages say not to retry.
 *
 * Pattern:
 *
 *   $client = $this->resolveJinaClientOrError();
 *   if (is_array($client)) {
 *       return $client;
 *   }
 */
trait ResolvesJinaClientForTool
{
    use HasKanvasContext;
    use NormalizesWebToolInput;

    /**
     * @return Client|array{error: string}
     */
    protected function resolveJinaClientOrError(): Client|array
    {
        if (! $this->hasTenantContext()) {
            report(new ValidationException(
                static::class . ' ran with no tenant context, so it could not resolve a Jina key. '
                . 'Register the tool through mergeRegisteredTools(), or call withContext($app, $company, $user).'
            ));

            return ['error' => 'This tool is not wired to a workspace and cannot run. Do not retry — tell '
                . 'the user an administrator needs to look at the agent configuration.'];
        }

        try {
            return new Client($this->app);
        } catch (Throwable) {
            return ['error' => 'Jina is not configured for this workspace, so web reading is unavailable. '
                . 'Do not retry — tell the user an administrator must add a Jina API key in the '
                . 'integration settings.'];
        }
    }
}
