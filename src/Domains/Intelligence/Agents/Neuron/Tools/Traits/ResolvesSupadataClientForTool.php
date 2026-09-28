<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\Supadata\Client;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Build the company's Supadata client from a tool's __invoke, or return a structured error the LLM
 * can act on.
 *
 * The key is resolved company-then-app, so an agent granted a transcription tool where neither is
 * configured would otherwise crash the chat on a ValidationException thrown from the client
 * constructor. Both failures here — no tenant context, no key — are permanent for the turn, so the
 * messages tell the model to stop rather than retry.
 *
 * Pattern:
 *
 *   $client = $this->resolveSupadataClientOrError();
 *   if (is_array($client)) {
 *       return $client;
 *   }
 */
trait ResolvesSupadataClientForTool
{
    use HasKanvasContext;
    use NormalizesWebToolInput;

    /**
     * @return Client|array{error: string}
     */
    protected function resolveSupadataClientOrError(): Client|array
    {
        if (! $this->hasTenantContext()) {
            report(new ValidationException(
                static::class . ' ran with no tenant context, so it could not resolve a Supadata key. '
                . 'Register the tool through mergeRegisteredTools(), or call withContext($app, $company, $user).'
            ));

            return ['error' => 'This tool is not wired to a workspace and cannot run. Do not retry — tell '
                . 'the user an administrator needs to look at the agent configuration.'];
        }

        try {
            return new Client($this->app, $this->company);
        } catch (Throwable) {
            return ['error' => 'Supadata is not configured for this company, so video transcription is '
                . 'unavailable. Do not retry — tell the user an administrator must connect Supadata for '
                . 'this company in the integration settings.'];
        }
    }
}
