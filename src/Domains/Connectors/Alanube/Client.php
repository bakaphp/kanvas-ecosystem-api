<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Enums\AccountModeEnum;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Exceptions\AlanubeRequestException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;

/**
 * Both account modes through one client (decision 14). In kanvas mode the token is the app's main-company
 * token and every company-specific call is scoped with the tenant's Alanube company id — either as
 * `/idCompany/{id}` on reads or `company.id` in the body on writes. In own mode the token already is the
 * company, so neither is added.
 *
 * Credentials are read on every construction, never cached in a static (Octane).
 */
class Client
{
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    // E32 under DOP 250k is answered synchronously: Alanube waits on DGII inside the request.
    private const int TIMEOUT_SECONDS = 90;

    public readonly AccountModeEnum $mode;
    public readonly bool $isProduction;
    private readonly string $token;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        ?AccountModeEnum $mode = null,
        ?EcfEnvironmentEnum $environment = null,
    ) {
        $this->mode = $mode
            ?? AccountModeEnum::tryFrom((string) $company->get(ConfigurationEnum::ACCOUNT_MODE->value))
            ?? AccountModeEnum::OWN;

        // A fiscal row passes its own environment: it is answered where it was issued, whatever the company says now.
        $this->isProduction = ($environment ?? EcfEnvironmentEnum::forCompany($company))->isProduction();
        $this->token = $this->resolveToken();
    }

    public function alanubeCompanyId(): ?string
    {
        $id = (string) $this->company->get(ConfigurationEnum::COMPANY_ID->value);

        return $id !== '' ? $id : null;
    }

    /**
     * `GET /fiscal-invoices/{id}` in own mode, `GET /fiscal-invoices/{id}/idCompany/{company}` in kanvas mode.
     */
    public function scopedPath(string $path): string
    {
        if ($this->mode === AccountModeEnum::OWN) {
            return $path;
        }

        return rtrim($path, '/') . '/idCompany/' . $this->requireCompanyId();
    }

    /**
     * The company record itself: `company` (the token's own) or `company/{id}` (an associated company).
     */
    public function companyPath(): string
    {
        return $this->mode === AccountModeEnum::OWN ? 'company' : 'company/' . $this->requireCompanyId();
    }

    /**
     * Alanube answers a company read or create either bare or wrapped in `company`.
     *
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    public static function companyRecord(array $response): array
    {
        return is_array($response['company'] ?? null) ? $response['company'] : $response;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function withCompany(array $body): array
    {
        if ($this->mode === AccountModeEnum::OWN) {
            return $body;
        }

        return ['company' => ['id' => $this->requireCompanyId()]] + $body;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>|null null on 404
     */
    public function get(string $path, array $query = []): ?array
    {
        $response = $this->request()->get($path, $query);

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->decode($this->request()->asJson()->post($path, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->decode($this->request()->asJson()->patch($path, $body));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function postFile(
        string $path,
        string $field,
        string $contents,
        string $fileName
    ): array {
        return $this->decode($this->request()->attach($field, $contents, $fileName)->post($path));
    }

    public function baseUrl(): string
    {
        return $this->isProduction ? ConfigurationEnum::PRODUCTION_URL : ConfigurationEnum::SANDBOX_URL;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if ($response->failed()) {
            $message = is_array($body) ? ($body['message'] ?? null) : null;
            $messages = is_array($message) ? array_map('strval', $message) : array_filter([(string) $message]);

            throw new AlanubeRequestException(
                status: $response->status(),
                messages: array_values($messages),
                alanubeCode: is_array($body) && isset($body['code']) ? (string) $body['code'] : null,
            );
        }

        return is_array($body) ? $body : [];
    }

    private function requireCompanyId(): string
    {
        return $this->alanubeCompanyId() ?? throw new ValidationException(
            'Alanube company id is not configured for company ' . $this->company->getId() . '. Run kanvas:alanube-setup.'
        );
    }

    private function resolveToken(): string
    {
        $token = $this->mode === AccountModeEnum::KANVAS
            ? $this->mainCompanyToken()
            : $this->ownToken();

        if ($token === '') {
            throw new ValidationException(
                'Alanube token is not configured for company ' . $this->company->getId() . " ({$this->mode->value} mode)."
            );
        }

        return $token;
    }

    private function mainCompanyToken(): string
    {
        $key = $this->isProduction ? ConfigurationEnum::MAIN_TOKEN_PRODUCTION : ConfigurationEnum::MAIN_TOKEN_TEST;

        return (string) ($this->app->get($key->value)
            ?: config('services.alanube.' . ($this->isProduction ? 'main_token_production' : 'main_token_test')));
    }

    private function ownToken(): string
    {
        return (string) $this->company->get(ConfigurationEnum::API_TOKEN->value);
    }
}
