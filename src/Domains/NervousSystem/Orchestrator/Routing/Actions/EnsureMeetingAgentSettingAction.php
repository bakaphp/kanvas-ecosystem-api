<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\MeetingAgentEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Services\MeetingAgentLocatorService;

/**
 * Keeps a company's meeting-agent setting pointing at a live agent. A setting that already names one
 * is left alone, so a manual choice survives a second agent of the same type being created; an empty
 * or dead one moves to the company's first agent of the type, or is cleared when there is none.
 */
class EnsureMeetingAgentSettingAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly MeetingAgentEnum $role
    ) {
    }

    public function execute(): ?Agent
    {
        $locator = new MeetingAgentLocatorService();

        $configured = $locator->configured($this->role, $this->app, $this->company);
        if ($configured !== null) {
            return $configured;
        }

        $first = $locator->firstOfType($this->role, $this->app, $this->company);
        if ($first === null) {
            $this->company->del($this->role->value);

            return null;
        }

        $this->company->set($this->role->value, $first->getId());

        return $first;
    }
}
