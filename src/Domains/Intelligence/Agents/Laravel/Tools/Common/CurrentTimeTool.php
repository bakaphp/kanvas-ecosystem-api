<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Common;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

#[AgentTool(name: 'Current Time')]
class CurrentTimeTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Get the current date and time. Use this to anchor any time-relative reasoning '
            . '(e.g. interpreting "Sunday", "tomorrow", "yesterday" in the conversation history) '
            . 'before deciding what to do. Pass an IANA timezone (e.g. "America/New_York") to get '
            . 'time in a specific zone; defaults to UTC.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $tz = $this->resolveTimezone((string) $request->string('timezone'));
        $now = Carbon::now($tz);

        return (string) json_encode([
            'timezone' => $tz,
            'iso_8601' => $now->toIso8601String(),
            'date' => $now->format('Y-m-d'),
            'time' => $now->format('H:i:s'),
            'day_of_week' => $now->englishDayOfWeek,
            'human' => $now->format('l, F j, Y \\a\\t g:i A'),
            'unix' => $now->getTimestamp(),
            'is_weekend' => $now->isWeekend(),
        ]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema
                ->string()
                ->description('Optional IANA timezone identifier (e.g. "America/New_York", "Europe/Madrid"). '
                    . 'Defaults to UTC when omitted or invalid.'),
        ];
    }

    private function resolveTimezone(string $timezone): string
    {
        $trimmed = trim($timezone);
        if ($trimmed === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($trimmed);
        } catch (Throwable) {
            return 'UTC';
        }

        return $trimmed;
    }
}
