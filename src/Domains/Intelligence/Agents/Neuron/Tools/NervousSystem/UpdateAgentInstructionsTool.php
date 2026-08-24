<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Actions\UpdateAgentInstructionsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Retune a teammate agent by conversation — "it writes up every lunch order, only cover launches" —
 * instead of filing a ticket. This is what makes an agent's first instructions not have to be right:
 * the thing worth optimising is how fast a person can correct a bad one.
 *
 * Three limits, each structural rather than advisory:
 *  - it changes what an agent is TOLD, never what it can TOUCH. Tools and channels stay on the
 *    hiring path, so retuning can never widen a grant;
 *  - an agent cannot edit ITSELF. Self-editing is how an agent talks its way out of its own
 *    constraints, and it is the one edit nobody else reviews;
 *  - the target must share a project with the caller, so an agent can retune its own team and
 *    nothing else in the company.
 */
#[AgentTool(name: 'Update Agent Instructions', category: 'nervous_system')]
class UpdateAgentInstructionsTool extends Tool
{
    use HasKanvasContext;

    public function __construct(
        private readonly ?Agent $editor = null,
    ) {
        parent::__construct(
            name: 'update_agent_instructions',
            description: 'Change what one of your teammate agents is told to do — its instructions, its '
                . 'persona, or the shape of its output. Use it when someone tells you an agent is getting '
                . 'something wrong: reword the part that is wrong and leave the rest alone. It does NOT '
                . 'change which tools that agent has. The previous wording is kept, so a bad edit can be '
                . 'undone. You cannot edit yourself.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The teammate to retune. It must be on a project you are on.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why it is changing, in the words of the person who asked. This is what the '
                    . 'next person reading the history will see.',
                required: true,
            ),
            new ToolProperty(
                name: 'instructions',
                type: PropertyType::STRING,
                description: 'The COMPLETE new instructions, not a patch — they replace what is there. '
                    . 'Keep the parts that were working. Omit to leave them unchanged.',
                required: false,
            ),
            new ToolProperty(
                name: 'soul',
                type: PropertyType::STRING,
                description: 'The complete new persona — who the agent is and how it carries itself. Omit '
                    . 'to leave it unchanged.',
                required: false,
            ),
            new ToolProperty(
                name: 'output_format',
                type: PropertyType::STRING,
                description: 'The complete new description of how its output should be shaped. Omit to '
                    . 'leave it unchanged.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $agent_id,
        string $reason,
        ?string $instructions = null,
        ?string $soul = null,
        ?string $output_format = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->error('This tool has no company context, so it cannot change an agent.');
        }

        if ($this->editor === null) {
            return $this->error('This tool does not know which agent is calling it, so it cannot check '
                . 'whether the target is on your team.');
        }

        if ($agent_id === $this->editor->getId()) {
            return $this->error('You cannot change your own instructions. Ask a person to do it.');
        }

        $target = Agent::query()
            ->whereKey($agent_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->first();

        if ($target === null) {
            return $this->error('Agent ' . $agent_id . ' is not in this company.');
        }

        if (! $this->mayRetune($target)) {
            return $this->error(
                'Agent ' . $target->name . ' is not on any project you are on, so you cannot retune it. '
                . 'Ask a person, or add it to the project first.'
            );
        }

        try {
            $version = new UpdateAgentInstructionsAction(
                agent: $target,
                editedBy: $this->editor->user ?? $this->user,
                reason: $reason,
                instructions: $instructions,
                soul: $soul,
                outputFormat: $output_format,
            )->execute();
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }

        return [
            'status' => 'success',
            'agent_id' => $target->getId(),
            'agent' => $target->name,
            'saved_version' => $version->version,
            'changed' => array_keys(array_filter([
                'instructions' => $instructions,
                'soul' => $soul,
                'output_format' => $output_format,
            ], fn (?string $value): bool => $value !== null)),
            'note' => 'It takes effect the next time that agent runs. Its previous wording is kept as '
                . 'version ' . $version->version . '.',
        ];
    }

    /**
     * The team boundary: agents you hired, or agents you work alongside. Anything wider would let one
     * agent rewrite every agent the tenant owns.
     *
     * Hires are included because they are otherwise unreachable — a fresh hire belongs to no project
     * yet, so a project-only rule would leave the agent that created it unable to correct it.
     */
    private function mayRetune(Agent $target): bool
    {
        if ((int) $target->parent_id === $this->editor->getId()) {
            return true;
        }

        return $this->sharesAProjectWithEditor($target);
    }

    private function sharesAProjectWithEditor(Agent $target): bool
    {
        $editorProjects = ProjectMember::query()
            ->where('agent_id', $this->editor->getId())
            ->where('is_deleted', 0)
            ->pluck('project_id');

        if ($editorProjects->isEmpty()) {
            return false;
        }

        return ProjectMember::query()
            ->where('agent_id', $target->getId())
            ->whereIn('project_id', $editorProjects)
            ->where('is_deleted', 0)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message): array
    {
        return ['status' => 'error', 'updated' => false, 'message' => $message];
    }
}
