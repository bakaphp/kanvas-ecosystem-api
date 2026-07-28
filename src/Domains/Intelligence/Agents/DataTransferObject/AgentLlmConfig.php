<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig as AgentLlmConfigModel;
use Spatie\LaravelData\Data;

class AgentLlmConfig extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly AgentLlmProviderEnum $provider,
        public readonly ?string $base_uri = null,
        public readonly ?string $api_key = null,
        public readonly ?string $model = null,
        public readonly ?array $config = null,
        public readonly bool $is_active = true,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        CompanyInterface $company,
        array $data,
    ): self {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            name: (string) $data['name'],
            provider: AgentLlmProviderEnum::from((string) $data['provider']),
            base_uri: $data['base_uri'] ?? null,
            api_key: $data['api_key'] ?? null,
            model: $data['model'] ?? null,
            config: $data['config'] ?? null,
            is_active: (bool) ($data['is_active'] ?? true),
        );
    }

    public static function forUpdate(
        AgentLlmConfigModel $config,
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        array $data,
    ): self {
        return new self(
            app: $app,
            company: $company,
            user: $user,
            name: (string) ($data['name'] ?? $config->name),
            provider: isset($data['provider'])
                ? AgentLlmProviderEnum::from((string) $data['provider'])
                : $config->providerEnum(),
            base_uri: array_key_exists('base_uri', $data) ? $data['base_uri'] : $config->base_uri,
            // Only overwrite the stored key when a non-empty one is sent, so partial
            // updates from a UI that never echoes the secret back don't wipe it.
            api_key: isset($data['api_key']) && $data['api_key'] !== '' ? $data['api_key'] : $config->api_key,
            model: array_key_exists('model', $data) ? $data['model'] : $config->model,
            config: array_key_exists('config', $data) ? $data['config'] : $config->config,
            is_active: (bool) ($data['is_active'] ?? $config->is_active),
        );
    }
}
