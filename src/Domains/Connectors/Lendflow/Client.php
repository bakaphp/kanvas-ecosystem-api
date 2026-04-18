<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Lendflow;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\App;
use Kanvas\Connectors\Lendflow\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Client
{
    protected const string SANDBOX_URL = 'https://sandbox-api.lendflow.com';
    protected const string PRODUCTION_URL = 'https://api.lendflow.com';

    protected GuzzleClient $httpClient;
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        $apiKey = (string) ($this->company->get(ConfigurationEnum::API_KEY->value) ?? '');
        if ($apiKey === '') {
            throw new ValidationException('Lendflow API key is not configured for company ' . (string) $this->company->getId());
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = $this->resolveBaseUrl();

        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout' => 60,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
        ]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function post(string $path, array $payload): array
    {
        $response = $this->httpClient->post($path, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $payload,
        ]);

        return $this->decode($response, 'POST ' . $path);
    }

    /**
     * Upload multiple files as multipart/form-data.
     *
     * @param list<array{name: string, contents: resource|string, file_type?: string|null}> $files
     */
    public function uploadMultipart(string $path, array $files): array
    {
        $multipart = [];
        foreach ($files as $i => $file) {
            $multipart[] = [
                'name' => "files[{$i}][file]",
                'contents' => $file['contents'],
                'filename' => $file['name'],
            ];
            $fileType = $file['file_type'] ?? null;
            if ($fileType !== null && $fileType !== '') {
                $multipart[] = [
                    'name' => "files[{$i}][file_type]",
                    'contents' => $fileType,
                ];
            }
        }

        $response = $this->httpClient->post($path, [
            'multipart' => $multipart,
        ]);

        return $this->decode($response, 'UPLOAD ' . $path);
    }

    protected function resolveBaseUrl(): string
    {
        $configured = $this->company->get(ConfigurationEnum::USE_SANDBOX->value);

        if ($configured === null) {
            return App::environment('production') ? self::PRODUCTION_URL : self::SANDBOX_URL;
        }

        if (is_bool($configured)) {
            return $configured ? self::SANDBOX_URL : self::PRODUCTION_URL;
        }

        if (is_int($configured)) {
            return $configured === 1 ? self::SANDBOX_URL : self::PRODUCTION_URL;
        }

        $normalized = is_string($configured) ? $configured : '';

        return in_array(
            $normalized,
            ['1', 'true', 'yes'],
            true
        ) ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    protected function decode(ResponseInterface $response, string $context): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            throw new RuntimeException(
                sprintf('Lendflow request failed [%d] %s: %s', $status, $context, $body)
            );
        }

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
