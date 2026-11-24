<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Only what is passed changes; the rest of the stored cadence stays.
 */
#[AgentTool(name: 'Set Reminder Cadence', category: 'accounting')]
class SetReminderCadenceTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ReportsToolOutcome;

    private const int MAX_OFFSET_DAYS = 365;

    protected string $name = 'set_reminder_cadence';

    protected ?string $description = 'Shows or changes when customers are reminded about unpaid invoices. Call it with no '
        . 'arguments to read the current cadence. steps are days relative to the due date (negative = before it, 0 = the '
        . 'due date), e.g. "-3,0,7,15,30"; after the last step and escalate_after_days past due the account owner is '
        . 'brought in; a customer reply pauses reminders for reply_pause_days. Pass only what the person wants changed. '
        . 'Only a company admin can change it.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'steps',
                type: PropertyType::STRING,
                description: 'Comma-separated day offsets from the due date, e.g. "-3,0,7,15,30".',
                required: false,
            ),
            new ToolProperty(
                name: 'enabled',
                type: PropertyType::BOOLEAN,
                description: 'False stops automatic reminders for the company; true turns them back on.',
                required: false,
            ),
            new ToolProperty(
                name: 'escalate_after_days',
                type: PropertyType::INTEGER,
                description: 'Days past due after which the account owner is brought in.',
                required: false,
            ),
            new ToolProperty(
                name: 'reply_pause_days',
                type: PropertyType::INTEGER,
                description: 'Days reminders wait after the customer answers one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $steps = null,
        ?bool $enabled = null,
        ?int $escalate_after_days = null,
        ?int $reply_pause_days = null,
    ): array {
        $current = DunningSchedule::forCompany($this->company);
        $steps = Str::trimToNull($steps);

        if ($steps === null && $enabled === null && $escalate_after_days === null && $reply_pause_days === null) {
            return $this->noop(['cadence' => $current->toArray()], 'This is the current cadence; nothing was changed.');
        }

        $denied = $this->requireAdminOrError();
        if ($denied !== null) {
            return $this->denied($denied['message'], guidance: 'Say the cadence was NOT changed and that a company admin has to ask for it.');
        }

        $offsets = $steps === null ? $current->steps : $this->offsets($steps);
        if ($offsets === null) {
            return $this->invalidArgs(
                "'{$steps}' is not a list of day offsets.",
                guidance: 'Nothing was changed. Use whole days between -' . self::MAX_OFFSET_DAYS . ' and ' . self::MAX_OFFSET_DAYS . ', e.g. "-3,0,7,15,30".',
            );
        }

        foreach (['escalate_after_days' => $escalate_after_days, 'reply_pause_days' => $reply_pause_days] as $field => $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_OFFSET_DAYS)) {
                return $this->invalidArgs("{$field} must be between 0 and " . self::MAX_OFFSET_DAYS . ' days.', guidance: 'Nothing was changed.');
            }
        }

        $cadence = DunningSchedule::fromArray([
            'enabled' => $enabled ?? $current->enabled,
            'steps' => $offsets,
            'escalate_after_days' => $escalate_after_days ?? $current->escalateAfterDays,
            'reply_pause_days' => $reply_pause_days ?? $current->replyPauseDays,
        ])->toArray();

        $this->company->set(DunningSchedule::SETTING, $cadence);

        return $this->ok(['cadence' => $cadence]);
    }

    /**
     * @return list<int>|null
     */
    private function offsets(string $steps): ?array
    {
        $offsets = [];
        foreach (explode(',', $steps) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[+-]?\d+$/', $part) !== 1 || abs((int) $part) > self::MAX_OFFSET_DAYS) {
                return null;
            }
            $offsets[] = (int) $part;
        }

        return $offsets === [] ? null : $offsets;
    }
}
