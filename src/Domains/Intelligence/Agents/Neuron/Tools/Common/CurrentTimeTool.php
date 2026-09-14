<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Baka\Support\DateHelper;
use Carbon\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Current Time', category: 'ecosystem')]
class CurrentTimeTool extends Tool
{
    public function __construct(private readonly ?string $defaultTimezone = null)
    {
        parent::__construct(
            name: 'get_current_time',
            description: 'Get the current date and time. Use this to anchor any time-relative reasoning '
                . '("in 1 minute", "tomorrow at 3pm", "Sunday") before deciding what to do — the returned '
                . 'time is already in the user\'s local timezone, so do your math in that same local time. '
                . 'Pass an IANA timezone (e.g. "America/New_York") to override the zone.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'timezone',
                type: ToolsPropertyType::STRING,
                description: 'Optional IANA timezone identifier (e.g. "America/New_York", "Europe/Madrid"). '
                    . 'Defaults to UTC when omitted or invalid.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $timezone = null): array
    {
        $tz = $this->resolveTimezone($timezone);
        $now = Carbon::now($tz);

        return [
            'timezone' => $tz,
            'iso_8601' => $now->toIso8601String(),
            'date' => $now->format('Y-m-d'),
            'time' => $now->format('H:i:s'),
            'day_of_week' => $now->englishDayOfWeek,
            'human' => $now->format('l, F j, Y \\a\\t g:i A'),
            'unix' => $now->getTimestamp(),
            'is_weekend' => $now->isWeekend(),
        ];
    }

    private function resolveTimezone(?string $timezone): string
    {
        $requested = trim((string) $timezone);
        $candidate = $requested !== '' ? $requested : (string) $this->defaultTimezone;

        return DateHelper::validTimezone($candidate) ?? 'UTC';
    }
}
