<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Connectors\Tavily\Client;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Build the app's Tavily client from a tool's __invoke, or return a structured error the LLM can act on.
 *
 * The key is per-app, so an agent granted a Tavily tool in a workspace that never configured one would
 * otherwise crash the chat on a ValidationException thrown from the client constructor. Both failures
 * here — no tenant context, no key — are permanent for the turn, so the messages tell the model to stop
 * rather than retry.
 *
 * Pattern:
 *
 *   $client = $this->resolveTavilyClientOrError();
 *   if (is_array($client)) {
 *       return $client;
 *   }
 */
trait ResolvesTavilyClientForTool
{
    use HasKanvasContext;
    use NormalizesWebToolInput;

    /**
     * @return Client|array{error: string}
     */
    protected function resolveTavilyClientOrError(): Client|array
    {
        if (! $this->hasTenantContext()) {
            report(new ValidationException(
                static::class . ' ran with no tenant context, so it could not resolve a Tavily key. '
                . 'Register the tool through mergeRegisteredTools(), or call withContext($app, $company, $user).'
            ));

            return ['error' => 'This tool is not wired to a workspace and cannot run. Do not retry — tell '
                . 'the user an administrator needs to look at the agent configuration.'];
        }

        try {
            return new Client($this->app);
        } catch (Throwable) {
            return ['error' => 'Tavily is not configured for this workspace, so web research is '
                . 'unavailable. Do not retry — tell the user an administrator must add a Tavily API key '
                . 'in the integration settings.'];
        }
    }

    /**
     * @return list<string>
     */
    protected function splitCommaList(?string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) $value),
        )));
    }

    /**
     * @param array<array-key, mixed> $results
     * @return list<array{url: string, content: string}>
     */
    protected function mapPagesFromResults(array $results, int $maxContentLength): array
    {
        return array_values(array_map(
            function (mixed $result) use ($maxContentLength): array {
                $result = is_array($result) ? $result : [];

                return [
                    'url' => (string) ($result['url'] ?? ''),
                    'content' => $this->truncateContent(
                        (string) ($result['raw_content'] ?? ''),
                        $maxContentLength,
                    ),
                ];
            },
            $results,
        ));
    }
}
