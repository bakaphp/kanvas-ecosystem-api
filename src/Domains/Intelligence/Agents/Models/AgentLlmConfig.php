<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Casts\Json;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Models\BaseModel;
use Override;

/**
 * A named, reusable LLM provider configuration an agent can be pointed at
 * (its config.llm_config_id). When an agent selects none, the resolver falls back
 * to the app-level global settings — see AgentProviderService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property string $name
 * @property string $slug
 * @property string $provider
 * @property string|null $base_uri
 * @property string|null $api_key
 * @property string|null $model
 * @property array|null $config
 * @property bool $is_active
 * @property bool $is_deleted
 */
class AgentLlmConfig extends BaseModel
{
    use SlugTrait;
    use UuidTrait;

    protected $table = 'agent_llm_configs';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'users_id',
        'name',
        'slug',
        'provider',
        'base_uri',
        'api_key',
        'model',
        'config',
        'is_active',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'config' => Json::class,
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function providerEnum(): AgentLlmProviderEnum
    {
        return AgentLlmProviderEnum::tryFrom($this->provider) ?? AgentLlmProviderEnum::GEMINI;
    }

    public function hasApiKey(): bool
    {
        return $this->api_key !== null && $this->api_key !== '';
    }

    #[Override]
    public static function newFactory(): AgentLlmConfigFactory
    {
        return AgentLlmConfigFactory::new();
    }
}
