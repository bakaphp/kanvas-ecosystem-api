<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Enums;

use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;

/**
 * The workflow rules setup still gives a company, by the name the rule is saved under. Each case
 * knows the event and module it listens on and the conditions that keep it to its own traffic; the
 * activities it runs are named in `config/communication.php`.
 *
 * What an agent does on its own (reach out to a new lead, answer on AI Assist, keep a lead's AI
 * mode, index its documents) is not here: those listen for the event themselves and are switched on
 * the agent; a person's reply in a lead conversation is delivered by Guild the same way. A rule is
 * for a step that triggers an external system, or one not yet moved.
 */
enum DefaultRuleEnum: string
{
    case PRIVATIZE_MESSAGES = 'Privatize Conversation Messages';
    case GOOGLE_CALENDAR_EVENT = 'Create Google Calendar Event';

    public function event(): WorkflowEnum
    {
        return match ($this) {
            self::PRIVATIZE_MESSAGES => WorkflowEnum::CREATED,
            self::GOOGLE_CALENDAR_EVENT => WorkflowEnum::EVENT_VERSIONS_WORKFLOW,
        };
    }

    /**
     * @return class-string
     */
    public function module(): string
    {
        return match ($this) {
            self::PRIVATIZE_MESSAGES => Message::class,
            self::GOOGLE_CALENDAR_EVENT => EventVersion::class,
        };
    }

    /**
     * @return list<class-string> in the order they run
     */
    public function activities(): array
    {
        return $this->definition()['activities'];
    }

    /**
     * The company setting the rule's vendor has to have written before the rule can run.
     */
    public function requiredCompanySetting(): ?string
    {
        return $this->definition()['requires_company_setting'] ?? null;
    }

    /**
     * @return array{activities: list<class-string>, requires_company_setting?: string}
     */
    private function definition(): array
    {
        return config('communication.default_rules')[$this->value];
    }

    /**
     * @return list<array{0: string, 1: RuleConditionOperatorEnum, 2: string}> joined with AND
     */
    public function conditions(): array
    {
        return [['id', RuleConditionOperatorEnum::GREATER_THAN, '0']];
    }
}
