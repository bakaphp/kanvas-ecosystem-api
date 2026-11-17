<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Phone\Concerns;

use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * How an agent's number on one channel is reported: the outcome is logged and kept on the
 * channel's config as `inbound_webhook`, which is what the admin UI shows.
 */
trait ReportsPhoneChannelStatus
{
    /**
     * @param array{status: string, message: string, url: string|null, warnings: list<string>} $result
     */
    private function recordStatus(Agent $agent, AgentPhoneChannelEnum $channel, array $result): void
    {
        $line = "{$channel->value} inbound [{$result['status']}] agent {$agent->uuid}: {$result['message']}";
        if (in_array($result['status'], ['error', 'conflict'], true)) {
            logger()->warning($line);
        } else {
            logger()->info($line);
        }

        $column = $channel->configColumn();
        $config = $agent->{$column} ?? [];

        // An agent that never had this channel keeps a null column, which is what the number lookups narrow on.
        if ($config === [] && $result['status'] === 'idle') {
            return;
        }

        $config['inbound_webhook'] = $result + ['at' => now()->toIso8601String()];

        // System metadata, not a user edit: it must not re-trigger observers or the save mutation.
        $agent->{$column} = $config;
        $agent->saveQuietly();
    }

    /**
     * @param list<string> $warnings
     *
     * @return array{status: string, message: string, url: string|null, warnings: list<string>}
     */
    private function status(
        string $status,
        string $message,
        ?string $url = null,
        array $warnings = []
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'url' => $url,
            'warnings' => $warnings,
        ];
    }
}
