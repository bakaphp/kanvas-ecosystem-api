<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Connectors\WebPos\Exceptions\WebPosRequestException;
use Kanvas\Exceptions\ValidationException;

/**
 * WebPOS's ApiKey routes (`/api/fedo/ak/...`): the key is a path segment, so a caller writes `API_KEY` where it
 * goes and the client fills it in at send time. Nothing that can reach a log or Sentry carries the real URL:
 * failures surface as WebPosRequestException with the key masked, and SentryUrlRedactor scrubs the HTTP
 * breadcrumbs and spans. Credentials are read on every construction, never cached in a static (Octane).
 */
class Client
{
    public const string API_KEY = '{apiKey}';

    private const int CONNECT_TIMEOUT_SECONDS = 10;

    // sendFileToProcess is synchronous: WebPOS signs and sends to DGII inside the request.
    private const int TIMEOUT_SECONDS = 90;

    public readonly EnvironmentEnum $environment;
    public readonly string $companyLicense;
    private readonly string $apiKey;

    /**
     * An environment passed in wins over the company setting: a fiscal row is always answered in the
     * environment it was issued in, even after the company switched from test to production.
     */
    public function __construct(
        public readonly CompanyInterface $company,
        ?EnvironmentEnum $environment = null,
    ) {
        $this->environment = $environment ?? EnvironmentEnum::forCompany($company);
        $this->companyLicense = (string) $company->get(ConfigurationEnum::COMPANY_LICENSE->value);
        $this->apiKey = (string) Str::trimToNull((string) $company->get(ConfigurationEnum::API_KEY->value));

        if ($this->companyLicense === '') {
            throw new ValidationException('WebPOS company license is not configured for company ' . $company->getId() . '.');
        }

        if ($this->apiKey === '') {
            throw new ValidationException('WebPOS API key is not configured for company ' . $company->getId() . '.');
        }
    }

    /**
     * @return array<array-key, mixed>|null null when WebPOS answers 404 (the document or RNC is not there)
     */
    public function get(HostEnum $host, string $path): ?array
    {
        $response = $this->send($host, $path, fn (PendingRequest $request, string $url): Response => $request->get($url));

        return $response->status() === 404 ? null : $this->decode($this->orFail($response));
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     */
    public function post(HostEnum $host, string $path, array $body = []): array
    {
        $response = $this->send(
            $host,
            $path,
            fn (PendingRequest $request, string $url): Response => $request->asJson()->post($url, $body)
        );

        return $this->decode($this->orFail($response));
    }

    /**
     * @param callable(PendingRequest, string): Response $call
     */
    private function send(HostEnum $host, string $path, callable $call): Response
    {
        $request = Http::baseUrl($this->environment->baseUrl($host))
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson();

        try {
            return $call($request, str_replace(self::API_KEY, rawurlencode($this->apiKey), $path));
        } catch (ConnectionException $e) {
            throw new WebPosRequestException('WebPOS could not be reached: ' . $this->mask($e->getMessage()));
        }
    }

    private function orFail(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $body = $this->mask((string) $response->body());

        throw new WebPosRequestException(
            "WebPOS answered HTTP {$response->status()}: " . mb_substr($body, 0, 500),
            $response->status(),
            $body
        );
    }

    private function mask(string $text): string
    {
        return str_replace([$this->apiKey, rawurlencode($this->apiKey)], self::API_KEY, $text);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }
}
