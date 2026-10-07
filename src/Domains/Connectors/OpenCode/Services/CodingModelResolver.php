<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Which provider and model an agent codes with: its own, or the app's.
 *
 * One resolver rather than a lookup at each site, because several exist and they must agree. The
 * session row records what was pinned and the poller kills a run whose messages come back from a
 * different model — so a config file that said one thing and a session row that said another would read
 * as the runtime silently substituting a model, and be shot for it.
 *
 * The provider block is all-or-nothing: an agent with its own provider id takes none of the app's
 * transport settings, because an OpenRouter id with OpenAI's base URL is a request to the wrong host.
 */
class CodingModelResolver
{
    /**
     * Chat completions, and anything OpenAI-shaped. `@ai-sdk/openai` is the other option and is
     * required for the codex models, which only exist behind the Responses API.
     */
    public const string DEFAULT_NPM = '@ai-sdk/openai-compatible';

    public const string DEFAULT_ENV_VAR = 'OPENAI_API_KEY';

    public function __construct(
        private readonly AppInterface $app,
        private readonly ?Agent $agent = null,
    ) {
    }

    public function model(): ?string
    {
        return $this->agentSetting(AgentCustomFieldEnum::MODEL)
            ?? Str::trimToNull((string) $this->app->get(ConfigurationEnum::MODEL->value));
    }

    public function hasOwnProvider(): bool
    {
        return $this->agentSetting(AgentCustomFieldEnum::PROVIDER_ID) !== null;
    }

    public function provider(): ?string
    {
        return $this->transport(AgentCustomFieldEnum::PROVIDER_ID, ConfigurationEnum::PROVIDER_ID);
    }

    public function baseUrl(): ?string
    {
        return $this->transport(AgentCustomFieldEnum::PROVIDER_BASE_URL, ConfigurationEnum::PROVIDER_BASE_URL);
    }

    public function npm(): string
    {
        return $this->transport(AgentCustomFieldEnum::PROVIDER_NPM, ConfigurationEnum::PROVIDER_NPM)
            ?? self::DEFAULT_NPM;
    }

    public function envVar(): string
    {
        return $this->transport(AgentCustomFieldEnum::PROVIDER_ENV_VAR, ConfigurationEnum::PROVIDER_ENV_VAR)
            ?? self::DEFAULT_ENV_VAR;
    }

    /**
     * `provider/model`, the form opencode pins a session to.
     */
    public function reference(): ?string
    {
        $provider = $this->provider();
        $model = $this->model();

        return $provider === null || $model === null ? null : $provider . '/' . $model;
    }

    private function transport(AgentCustomFieldEnum $onAgent, ConfigurationEnum $onApp): ?string
    {
        return $this->hasOwnProvider()
            ? $this->agentSetting($onAgent)
            : Str::trimToNull((string) $this->app->get($onApp->value));
    }

    private function agentSetting(AgentCustomFieldEnum $field): ?string
    {
        return Str::trimToNull((string) $this->agent?->get($field->value));
    }
}
