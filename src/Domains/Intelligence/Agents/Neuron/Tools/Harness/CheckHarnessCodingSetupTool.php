<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Connectors\OpenCode\DataTransferObject\CodingSetupCheck;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeReadinessService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Whether this agent can actually run a coding job, and what a person must fix if not.
 *
 * Every prerequisite here was discovered by dispatching a job and reading the failure off a session
 * row afterwards — no image, then an unwritable workspace, then a provider that could not be declared.
 * One per attempt, each error naming something other than the setting behind it. An agent that can
 * only answer "it failed" makes its person repeat that loop.
 *
 * Answers with the settings by name and who sets them, so the agent relays an instruction rather than
 * a symptom. It is a **read**: it configures nothing, and the things it reports are all admin-only by
 * design — an agent may not mint a git token or a provider key.
 */
#[AgentTool(name: 'Check Self-Hosted Coding Setup', category: 'coding')]
class CheckHarnessCodingSetupTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'check_self_hosted_coding_setup';

    protected ?string $description = 'Check whether you can actually run coding jobs, and what is missing if you '
        . 'cannot: the git token, the machine, the runtime image, the provider and model, and '
        . 'the API key. Use it before the first job on a new setup, whenever a job fails for a '
        . 'reason that sounds like configuration, and whenever someone asks if you are ready to '
        . 'code. Report what it names verbatim — each item says which setting and who sets it.';

    public function __construct(
        private readonly Agent $agent,
        private readonly ?CodingRuntimeReadinessService $readiness = null,
    ) {
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'check_machine',
                type: PropertyType::BOOLEAN,
                description: 'Also check the machine itself — docker, disk permissions, whether the image '
                    . 'is built there. Slower (one ssh round trip). Default true; pass false for a quick '
                    . 'settings-only answer.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?bool $check_machine = null): array
    {
        $readiness = $this->readiness ?? new CodingRuntimeReadinessService();

        try {
            $checks = $readiness->checksForAgent($this->agent);
            $problems = $readiness->failures($checks);
            $machine = $readiness->machineFor($this->agent, $problems);

            if (($check_machine ?? true) && $machine !== null) {
                $problems = [...$problems, ...$readiness->hostProblems($machine, $this->agent->app)];
            }

            $needsAdmin = array_values(array_map(
                static fn (CodingSetupCheck $check): string => $check->setting,
                array_filter($checks, static fn (CodingSetupCheck $check): bool => $check->needsAdministrator),
            ));
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                $e->getMessage(),
                guidance: 'The check itself failed, so say you could not confirm the setup rather than '
                    . 'that it is broken.'
            );
        }

        $settings = array_map(
            static fn (CodingSetupCheck $check): array => $check->toArray(),
            $checks
        );

        if ($problems === []) {
            return $this->ok(
                ['ready' => true, 'machine' => $machine?->name, 'settings' => $settings],
                guidance: 'Everything needed is configured. Dispatch the work rather than asking anyone '
                    . 'to check anything.'
            );
        }

        return $this->noop(
            [
                'ready' => false,
                'machine' => $machine?->name,
                'missing' => $problems,
                'settings' => $settings,
                'needs_administrator' => $needsAdmin,
            ],
            'This agent cannot run coding jobs yet. Tell the person every item in `missing`, as written — '
                . 'each says what is wrong and exactly how to fix it. Anything listed in '
                . '`needs_administrator` only an admin can set, so say so plainly and name the setting '
                . 'rather than implying they can do it themselves; the rest are commands someone with '
                . 'server access runs. `settings` shows what IS configured, so use it to say how far '
                . 'along they are instead of only what is broken. Do not dispatch until it is resolved.'
        );
    }
}
