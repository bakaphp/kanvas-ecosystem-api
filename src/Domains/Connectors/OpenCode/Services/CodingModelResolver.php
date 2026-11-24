<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
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

    /**
     * Company wins over app, like the key.
     */
    public function fallbackModel(): ?string
    {
        return Str::trimToNull((string) (
            $this->agent?->company?->get(ConfigurationEnum::FALLBACK_MODEL->value)
            ?? $this->app->get(ConfigurationEnum::FALLBACK_MODEL->value)
        ));
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

    /**
     * The provider key this agent's runtime starts with.
     *
     * Per agent before per tenant, for the same reason the git token and the machine are: an agent's
     * reach should be settable on the agent. One can run an expensive model while another runs a cheap
     * one, and a key's spend can be attributed to a single agent instead of pooled across the company.
     *
     * A missing named setting is an error rather than a fall-back to the tenant default. Quietly using
     * a different key than the one named would bill the wrong budget and send the tenant's code to a
     * provider account nobody chose — the same class of problem as a substituted model.
     */
    public function apiKey(): string
    {
        $company = $this->agent?->company;
        $own = $this->agentSetting(AgentCustomFieldEnum::PROVIDER_API_KEY);

        if ($own !== null) {
            return $own;
        }

        $named = $this->agentSetting(AgentCustomFieldEnum::PROVIDER_KEY_NAME);

        if ($named !== null) {
            $key = Str::trimToNull((string) ($company?->get($named) ?? $this->app->get($named)));

            if ($key === null) {
                throw new ValidationException(
                    'Agent ' . $this->agent?->name . ' is set to use the provider key "' . $named
                    . '", but no such setting exists on its company or app.'
                );
            }

            return $key;
        }

        // The tenant key was issued by the tenant's provider. Handing it to a different one leaks it to a
        // third party and fails authentication anyway.
        if ($this->hasOwnProvider()) {
            throw new ValidationException(
                'Agent ' . $this->agent?->name . ' has its own provider ('
                . AgentCustomFieldEnum::PROVIDER_ID->value . ') but no key of its own. Set '
                . AgentCustomFieldEnum::PROVIDER_API_KEY->value . ' or '
                . AgentCustomFieldEnum::PROVIDER_KEY_NAME->value . ' on the agent.'
            );
        }

        return Str::trimToNull((string) $company?->get(ConfigurationEnum::PROVIDER_API_KEY->value))
            ?? Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_API_KEY->value))
            ?? '';
    }

    /**
     * Identifies the key without storing or logging it — the runtime carries this as a label readable by
     * anyone who can inspect it. The variable name is part of it: an agent moved to a provider that
     * reads its key from a different variable needs a new runtime even when the key itself is unchanged.
     */
    public function keyFingerprint(): string
    {
        return mb_substr(hash('sha256', $this->envVar() . "\0" . $this->apiKey()), 0, 16);
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
