<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta;

use Baka\Contracts\AppInterface;
use Baka\Http\SafeUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use RuntimeException;

/**
 * The WhatsApp Cloud API, over Meta's Graph API. Every call throws RequestException when Meta
 * answers with an error, and lets a connection failure through as is.
 */
class Client
{
    public const string DEFAULT_GRAPH_VERSION = 'v23.0';

    public function __construct(
        protected string $accessToken,
        protected string $graphVersion = self::DEFAULT_GRAPH_VERSION
    ) {
    }

    public static function forApp(AppInterface $app, string $accessToken): self
    {
        return new self(
            $accessToken,
            (string) ($app->get(ConfigurationEnum::GRAPH_API_VERSION->value) ?? self::DEFAULT_GRAPH_VERSION)
        );
    }

    /**
     * With the token the company set up its number with.
     */
    public static function forCompany(AppInterface $app, Companies $company): self
    {
        $token = $company->get(ConfigurationEnum::ACCESS_TOKEN->value);

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Company ' . $company->getId() . ' has no WhatsApp access token.');
        }

        return self::forApp($app, $token);
    }

    /**
     * @return array{id: string, display_phone_number?: string, verified_name?: string, quality_rating?: string}
     */
    public function phoneNumber(string $phoneNumberId): array
    {
        return $this->request()
            ->get(self::graphId($phoneNumberId), ['fields' => 'display_phone_number,verified_name,quality_rating'])
            ->throw()
            ->json();
    }

    /**
     * @return array{messages?: list<array{id: string}>}
     */
    public function sendMessage(string $phoneNumberId, array $message): array
    {
        return $this->request()
            ->post(self::graphId($phoneNumberId) . '/messages', ['messaging_product' => 'whatsapp'] + $message)
            ->throw()
            ->json();
    }

    /**
     * @return array{url: string, mime_type: string}
     */
    public function media(string $mediaId): array
    {
        return $this->request()->get(self::graphId($mediaId))->throw()->json();
    }

    /**
     * The URL comes from Meta's answer, but it is still fetched with our token, so it is held to
     * the same guard as any other remote URL.
     */
    public function downloadMedia(string $url): string
    {
        SafeUrl::assertSafe($url);

        return Http::withToken($this->accessToken)->timeout(60)->get($url)->throw()->body();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function subscribedApps(string $wabaId): array
    {
        return $this->request()->get(self::graphId($wabaId) . '/subscribed_apps')->throw()->json('data') ?? [];
    }

    /**
     * Subscribes the token's app to the WABA's webhooks, sent to $callbackUrl instead of the app's
     * own. Meta verifies the URL with a GET carrying $verifyToken before it answers.
     */
    public function subscribe(string $wabaId, string $callbackUrl, string $verifyToken): void
    {
        $this->request()
            ->post(self::graphId($wabaId) . '/subscribed_apps', [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken,
            ])
            ->throw();
    }

    public function unsubscribe(string $wabaId): void
    {
        $this->request()->delete(self::graphId($wabaId) . '/subscribed_apps')->throw();
    }

    /**
     * Meta's own code for a refusal, e.g. 131047 for a message outside the 24-hour window.
     */
    public static function errorCode(RequestException $exception): ?string
    {
        $code = $exception->response->json('error.code');

        return $code !== null ? (string) $code : null;
    }

    public static function errorMessage(RequestException $exception): string
    {
        return (string) ($exception->response->json('error.error_data.details')
            ?? $exception->response->json('error.message')
            ?? $exception->getMessage());
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/' . $this->graphVersion . '/')
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * Graph ids are numeric. Anything else would let a tenant's input pick another Graph path.
     */
    private static function graphId(string $id): string
    {
        if (! ctype_digit($id)) {
            throw new InvalidArgumentException('Not a Meta Graph id: ' . $id);
        }

        return $id;
    }
}
