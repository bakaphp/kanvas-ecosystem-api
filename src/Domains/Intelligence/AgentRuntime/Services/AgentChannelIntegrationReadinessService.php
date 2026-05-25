<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentDeploymentMissingChannelIntegrationNotification;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

class AgentChannelIntegrationReadinessService
{
    public function assertReadyForDeployment(Agent $agent, string $provider): void
    {
        if ($this->isReady($agent)) {
            return;
        }

        $this->notifyMissingIntegration($agent, $provider);

        throw new ValidationException(
            'Agent deployment requires a complete Slack integration or Telegram integration before launch.',
        );
    }

    public function isReady(Agent $agent): bool
    {
        return $this->hasSlackIntegration($agent) || $this->hasTelegramIntegration($agent);
    }

    public function hasSlackIntegration(Agent $agent): bool
    {
        return $this->filled($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value))
            && $this->filled($agent->get(AgentChannelTokenEnum::SLACK_APP_TOKEN->value));
    }

    public function hasTelegramIntegration(Agent $agent): bool
    {
        return $this->filled($agent->get(AgentChannelTokenEnum::TELEGRAM_BOT_TOKEN->value))
            && $this->filled($agent->get(AgentChannelTokenEnum::TELEGRAM_ALLOWED_USERS->value));
    }

    /**
     * @return array<int, string>
     */
    public function missingRequirements(Agent $agent): array
    {
        $missing = [];

        if (! $this->filled($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value))) {
            $missing[] = 'Slack bot token';
        }

        if (! $this->filled($agent->get(AgentChannelTokenEnum::SLACK_APP_TOKEN->value))) {
            $missing[] = 'Slack app token';
        }

        if (! $this->filled($agent->get(AgentChannelTokenEnum::TELEGRAM_BOT_TOKEN->value))) {
            $missing[] = 'Telegram bot token';
        }

        if (! $this->filled($agent->get(AgentChannelTokenEnum::TELEGRAM_ALLOWED_USERS->value))) {
            $missing[] = 'Telegram allowed users';
        }

        return $missing;
    }

    private function filled(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    private function notifyMissingIntegration(Agent $agent, string $provider): void
    {
        $recipient = $agent->user;
        if (! $recipient instanceof Users) {
            return;
        }

        $recipient->notify(new AgentDeploymentMissingChannelIntegrationNotification(
            $agent,
            $provider,
            $this->missingRequirements($agent),
        ));
    }
}
