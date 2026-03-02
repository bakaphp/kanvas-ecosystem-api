<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl = 'https://api.respond.io/v2';
    protected string $bearerToken;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->bearerToken = $company->get(ConfigurationEnum::BEARER_TOKEN->value)
            ?? $app->get(ConfigurationEnum::BEARER_TOKEN->value);

        if (! $this->bearerToken) {
            throw new ValidationException('Respond.io bearer token is not set.');
        }
    }

    public function get(string $path, array $params = []): array
    {
        $response = Http::withToken($this->bearerToken)
            ->get($this->baseUrl . $path, $params);

        return $response->json() ?? [];
    }

    public function post(string $path, array $data = [], array $params = []): array
    {
        $response = Http::withToken($this->bearerToken)
                        ->withOptions($params)
                        ->post($this->baseUrl . $path, $data);

        return $response->json() ?? [];
    }

    public function sendMessage(string $phone, string $message, array $params = []): array
    {
        $phone = $this->normalizePhone($phone);

        $path = "/contact/phone:{$phone}/message";
        $data = [
            'message' => [
                'type' => 'text',
                'text' => $message,
            ],
        ];

        return $this->post($path, $data, $params);
    }

    public function sendAttachment(
        string $phone,
        string $type,
        string $url,
        ?string $caption = null
    ): array {
        $phone = $this->normalizePhone($phone);

        $path = "/contact/phone:{$phone}/message";
        $attachment = [
            'type' => $type,
            'url' => $url,
        ];

        if ($caption !== null) {
            $attachment['caption'] = $caption;
        }

        $data = [
            'message' => [
                'type' => 'attachment',
                'attachment' => $attachment,
            ],
        ];

        return $this->post($path, $data);
    }

    public static function validateCredentials(string $bearerToken): bool
    {
        try {
            $response = Http::withToken($bearerToken)
                ->get('https://api.respond.io/v2/contact', ['page_size' => 1]);

            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    protected function normalizePhone(string $phone): string
    {
        if (! Str::startsWith($phone, ['1', '+'])) {
            $phone = '+1' . $phone;
        }
        if (! Str::startsWith($phone, ['+'])) {
            $phone = '+' . $phone;
        }

        return $phone;
    }
}
