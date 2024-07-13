<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
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
        if (! $app->get(ConfigurationEnum::BEAR_TOKEN_AUTH->value)) {
            throw new ValidationException('Respond.io bearer token is not set on app settings.');
        }

        $this->bearerToken = $app->get(ConfigurationEnum::BEAR_TOKEN_AUTH->value);
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
        if (! Str::startsWith($phone, ['1', '+'])) {
            $phone = '+1' . $phone;
        }
        if (! Str::startsWith($phone, ['+'])) {
            $phone = '+' . $phone;
        }

        $path = "/contact/phone:$phone/message";
        $data = [
            'message' => [
                'type' => 'text',
                'text' => $message,
            ],
        ];

        return $this->post($path, $data, $params);
    }
}
