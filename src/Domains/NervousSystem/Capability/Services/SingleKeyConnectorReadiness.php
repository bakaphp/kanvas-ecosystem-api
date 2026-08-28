<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\Contracts\ProvidesConnectorReadiness;
use Kanvas\NervousSystem\Capability\DataTransferObject\ConnectorReadiness;

/**
 * Base for the common case: a connector that is configured, or not, by one app-level setting.
 *
 * Most connectors are exactly this — an API key or a credentials blob, present or absent. Writing the
 * same "read the key, return ready or notReady with a sentence naming the key" three times invites the
 * fourth to drift, so subclasses declare the key and the wording and inherit the check.
 *
 * A connector needing more than one setting, or a live probe, implements
 * {@see ProvidesConnectorReadiness} directly instead.
 */
abstract class SingleKeyConnectorReadiness implements ProvidesConnectorReadiness
{
    /** The app setting that has to be present, e.g. `TAVILY_API_KEY`. */
    abstract protected function configKey(): string;

    /** Named check reported back, e.g. `api_key` — what an admin is being told is missing. */
    abstract protected function checkName(): string;

    /** What the admin has to do, without the key name — that is appended from `configKey()`. */
    abstract protected function setupInstruction(): string;

    public function readiness(AppInterface $app): ConnectorReadiness
    {
        $value = $app->get($this->configKey());
        $configured = is_string($value) && trim($value) !== '';

        if ($configured) {
            return ConnectorReadiness::ready($this->slug(), $this->label(), [$this->checkName() => true]);
        }

        return ConnectorReadiness::notReady(
            $this->slug(),
            $this->label(),
            [$this->checkName() => false],
            [$this->setupInstruction() . ' ' . $this->configKey() . '.'],
        );
    }
}
