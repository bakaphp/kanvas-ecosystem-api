<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Psr\Http\Message\RequestInterface;

/**
 * Builds a Claude Managed Agents Client backed by canned HTTP responses, so tests exercise the real
 * request-building and response-mapping path rather than a stubbed-out service.
 */
trait HasClaudeAgentConfiguration
{
    /**
     * @param array<string, mixed> $attributes
     */
    protected function makeClaudeAgent(
        AppInterface $app,
        CompanyInterface $company,
        array $attributes = [],
    ): Agent {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Hosted Agent',
                'config' => ['claude_model' => 'claude-opus-5'],
                ...$attributes,
            ]);
    }

    protected function configureClaudeAgent(AppInterface $app, CompanyInterface $company): void
    {
        $app->set(ConfigurationEnum::BASE_URL->value, 'https://claude.test');
        $company->set(ConfigurationEnum::API_KEY->value, 'sk-ant-test-key');
    }

    protected function clearClaudeAgentConfiguration(AppInterface $app, CompanyInterface $company): void
    {
        $app->set(ConfigurationEnum::BASE_URL->value, '');
        $app->set(ConfigurationEnum::API_KEY->value, '');
        $company->set(ConfigurationEnum::API_KEY->value, '');
    }

    /**
     * @param list<Response> $responses Returned in order as the client makes requests.
     */
    protected function claudeAgentClientReturning(
        AppInterface $app,
        CompanyInterface $company,
        array $responses,
    ): Client {
        $this->configureClaudeAgent($app, $company);

        return new Client(
            $app,
            $company,
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    /**
     * Like claudeAgentClientReturning(), but records each outbound request body keyed by path so a
     * test can assert on the payload we actually built, not just the reply we mocked.
     *
     * @param list<Response> $responses
     * @param array<string, array<string, mixed>>|null $captured Filled by reference.
     */
    protected function claudeAgentClientCapturing(
        AppInterface $app,
        CompanyInterface $company,
        array $responses,
        ?array &$captured,
    ): Client {
        $this->configureClaudeAgent($app, $company);
        $captured = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::mapRequest(
            static function (RequestInterface $request) use (&$captured): RequestInterface {
                $decoded = json_decode((string) $request->getBody(), true);
                $captured[$request->getUri()->getPath()] = is_array($decoded) ? $decoded : [];

                return $request;
            },
        ));

        return new Client($app, $company, new GuzzleClient(['handler' => $stack]));
    }

    /**
     * @param list<Response> $responses
     */
    protected function claudeAgentGuzzleReturning(array $responses): GuzzleClient
    {
        return new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    /**
     * A handler that answers every request with the same body, however many times it is asked.
     * The queue-based MockHandler can't express that, and a poll loop makes an unbounded number of
     * requests — queueing "enough" responses would just be a slower way to fail.
     *
     * @param array<string, mixed> $body
     */
    protected function claudeAgentGuzzleRepeating(int $status, array $body): GuzzleClient
    {
        $json = (string) json_encode($body);

        return new GuzzleClient([
            'handler' => HandlerStack::create(
                // A fresh Response per call: reusing one would hand back an already-consumed body
                // stream on the second request.
                static fn (RequestInterface $request, array $options) => Create::promiseFor(
                    new Response($status, ['Content-Type' => 'application/json'], $json),
                ),
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function claudeAgentJsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    /** File downloads return bytes, not JSON — the one response that must not be decoded. */
    protected function claudeAgentRawResponse(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/octet-stream'], $body);
    }
}
