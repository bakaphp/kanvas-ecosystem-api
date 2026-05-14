<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Listeners;

use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentDeploymentFailedNotification;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentDeploymentLaunchedNotification;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentDeploymentTerminatedNotification;
use Kanvas\Users\Models\Users;

class SendAgentDeploymentLifecycleEmail
{
    public function handle(AgentDeploymentStatusChanged $event): void
    {
        $deployment = $event->deployment;
        $newStatus = $deployment->status;

        // Only act on transitions that actually change state — re-emissions on the same status
        // happen during retries and would spam the inbox.
        if ($newStatus === $event->previousStatus) {
            return;
        }

        $notification = match ($newStatus) {
            DeploymentStatusEnum::RUNNING->value => new AgentDeploymentLaunchedNotification($deployment),
            DeploymentStatusEnum::TERMINATED->value => new AgentDeploymentTerminatedNotification($deployment),
            DeploymentStatusEnum::FAILED->value => new AgentDeploymentFailedNotification($deployment),
            default => null,
        };

        if ($notification === null) {
            return;
        }

        $recipient = $deployment->agent?->user;
        if (! $recipient instanceof Users) {
            return;
        }

        $recipient->notify($notification);
    }
}
