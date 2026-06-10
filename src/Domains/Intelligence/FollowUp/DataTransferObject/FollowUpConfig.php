<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Enums\ChannelSelectionEnum;
use Kanvas\Intelligence\FollowUp\Enums\ExhaustedActionEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Spatie\LaravelData\Data;

class FollowUpConfig extends Data
{
    public function __construct(
        public readonly bool $enabled,
        public readonly FollowUpModeEnum $mode,
        public readonly ?TimeBasedConfig $timeBased,
        public readonly ?GoalBasedConfig $goalBased,
        public readonly int $maxRetries,
        public readonly ExhaustedActionEnum $exhaustedAction,
        public readonly ?string $agentName,
        // Optional Blade override of the agent prompt — per-stage tone tuning.
        public readonly ?string $promptTemplate,
        /** @var ChannelConfig[] */
        public readonly array $channels,
        public readonly ChannelSelectionEnum $channelSelection,
        public readonly bool $respectWorkHours,
        public readonly bool $respectLeadOptOuts,
        public readonly bool $writeSystemMessageOnStageChange,
    ) {
    }

    // Returns null when stage has no `follow_up` block at all. Disabled blocks
    // still produce a DTO (callers check `->enabled`) — keeping JSON-faithful
    // makes read-modify-write flows safer than treating absence == disabled.
    public static function fromStage(PipelineStage $stage): ?self
    {
        $raw = $stage->config['follow_up'] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $mode = FollowUpModeEnum::from((string) ($raw['mode'] ?? FollowUpModeEnum::TIME_BASED->value));

        $timeBased = isset($raw['time_based']) && is_array($raw['time_based'])
            ? new TimeBasedConfig(
                intervalMinutes: (int) ($raw['time_based']['interval_minutes'] ?? 1440),
                advanceAfterMaxRetries: (bool) ($raw['time_based']['advance_after_max_retries'] ?? false),
            )
            : null;

        $goalBased = isset($raw['goal_based']) && is_array($raw['goal_based'])
            ? new GoalBasedConfig(
                goalSignals: (array) ($raw['goal_based']['goal_signals'] ?? []),
                maxWaitMinutes: isset($raw['goal_based']['max_wait_minutes'])
                    ? (int) $raw['goal_based']['max_wait_minutes']
                    : null,
                onMaxWait: $raw['goal_based']['on_max_wait'] ?? null,
            )
            : null;

        $channels = [];
        foreach ((array) ($raw['channels'] ?? []) as $channelRaw) {
            if (! is_array($channelRaw) || ! isset($channelRaw['type'])) {
                continue;
            }

            $channels[] = new ChannelConfig(
                type: (string) $channelRaw['type'],
                enabled: (bool) ($channelRaw['enabled'] ?? false),
                templateName: isset($channelRaw['template_name']) && is_string($channelRaw['template_name'])
                    ? $channelRaw['template_name']
                    : null,
            );
        }

        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            mode: $mode,
            timeBased: $timeBased,
            goalBased: $goalBased,
            maxRetries: (int) ($raw['max_retries'] ?? 5),
            exhaustedAction: ExhaustedActionEnum::from(
                (string) ($raw['exhausted_action'] ?? ExhaustedActionEnum::STOP->value)
            ),
            agentName: isset($raw['agent_name']) ? (string) $raw['agent_name'] : null,
            promptTemplate: isset($raw['prompt_template']) && is_string($raw['prompt_template'])
                ? $raw['prompt_template']
                : null,
            channels: $channels,
            channelSelection: ChannelSelectionEnum::from(
                (string) ($raw['channel_selection'] ?? ChannelSelectionEnum::STICKY_THEN_PRIORITY->value)
            ),
            respectWorkHours: (bool) ($raw['respect_work_hours'] ?? true),
            respectLeadOptOuts: (bool) ($raw['respect_lead_opt_outs'] ?? true),
            writeSystemMessageOnStageChange: (bool) ($raw['write_system_message_on_stage_change'] ?? true),
        );
    }

    public function channelByType(string $type): ?ChannelConfig
    {
        foreach ($this->channels as $channel) {
            if ($channel->type === $type && $channel->enabled) {
                return $channel;
            }
        }

        return null;
    }
}
