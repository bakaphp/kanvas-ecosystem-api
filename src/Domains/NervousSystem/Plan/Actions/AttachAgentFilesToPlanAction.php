<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;

/**
 * Files an agent produced go onto a plan, never into the conversation: a day's export would bury the
 * turn, and a plan is addressable — the agent can hand it to another agent or a later run.
 *
 * Shared by everything that produces files for an agent: a finished MCP background job, a browser
 * session about to be closed.
 */
class AttachAgentFilesToPlanAction
{
    public const string PLAN_TYPE = 'agent_files';

    /**
     * @param array<string, Filesystem> $files file name => the stored file
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Users $user,
        private readonly array $files,
        private readonly string $title,
        private readonly ?string $description = null,
        private readonly ?Plan $plan = null,
        private readonly ?string $entityNamespace = null,
        private readonly ?int $entityId = null,
    ) {
    }

    public function execute(): ?Plan
    {
        if ($this->files === []) {
            return null;
        }

        $plan = $this->plan ?? $this->create();

        foreach ($this->files as $name => $file) {
            $plan->addFile($file, $name);
        }

        return $plan;
    }

    private function create(): Plan
    {
        return new CreatePlanAction(
            new PlanData(
                app: $this->agent->app,
                company: $this->agent->company,
                title: $this->title,
                planType: self::PLAN_TYPE,
                agent: $this->agent,
                user: $this->user,
                entityNamespace: $this->entityNamespace,
                entityId: $this->entityId,
                description: $this->description ?? $this->fileList(),
                status: PlanStatusEnum::DONE,
            ),
        )->execute();
    }

    private function fileList(): string
    {
        return implode("\n", array_map(
            static fn (string $name): string => '- ' . $name,
            array_keys($this->files)
        ));
    }
}
