<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Which model an agent codes with: its own, or the app's.
 *
 * One resolver rather than a lookup at each site, because two of them exist and they must agree. The
 * session row records what was pinned and the poller kills a run whose messages come back from a
 * different model — so a config file that said one thing and a session row that said another would read
 * as the runtime silently substituting a model, and be shot for it.
 */
class CodingModelResolver
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly ?Agent $agent = null,
    ) {
    }

    public function model(): ?string
    {
        return Str::trimToNull((string) $this->agent?->get(AgentCustomFieldEnum::MODEL->value))
            ?? Str::trimToNull((string) $this->app->get(ConfigurationEnum::MODEL->value));
    }

    public function provider(): ?string
    {
        return Str::trimToNull((string) $this->app->get(ConfigurationEnum::PROVIDER_ID->value));
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
}
