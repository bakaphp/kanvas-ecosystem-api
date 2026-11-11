<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Users\Models\Users;

/**
 * Hands an AP/AR agent one piece of work to do now (a one-off AGENT_TASK a minute out), on behalf of the
 * person it concerns, linked to the record it is about.
 */
class CreateAccountingAgentTaskAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Users $onBehalfOf,
        private readonly string $instruction,
        private readonly ?string $sourceEntityType = null,
        private readonly ?int $sourceEntityId = null,
    ) {
    }

    public function execute(): ScheduledAction
    {
        return new CreateScheduledActionAction(
            new ScheduledActionData(
                app: $this->agent->app,
                company: $this->agent->company,
                user: $this->onBehalfOf,
                type: ScheduledActionTypeEnum::AGENT_TASK,
                timezone: 'UTC',
                runAt: Carbon::now()->addMinute(),
                agent: $this->agent,
                instruction: $this->instruction,
                sourceEntityType: $this->sourceEntityType,
                sourceEntityId: $this->sourceEntityId !== null ? (string) $this->sourceEntityId : null,
            ),
        )->execute();
    }
}
