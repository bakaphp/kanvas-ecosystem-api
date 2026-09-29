<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Actions\SetAgentGitIdentityAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\GitIdentity;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Lets the person talking to the agent have its commits authored as them — the fix when a deploy
 * platform (Vercel) blocks a commit from an email no team member owns.
 *
 * Takes no email as input, on purpose. Whoever sets the identity chooses whose name the work ships
 * under, so the agent may only choose the authenticated human in front of it or the default. Any other
 * email is `kanvas:coding:git-identity`, which an admin runs.
 */
#[AgentTool(name: 'Set Self-Hosted Coding Commit Identity', category: 'coding')]
class SetHarnessCommitIdentityTool extends Tool implements HasRunKey, RequiresSystemAgent
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Users $requestingHuman = null,
    ) {
        parent::__construct(
            name: 'set_coding_commit_identity',
            description: 'Change who your coding commits are authored by. "me" makes your commits carry '
                . 'the name and email of the person you are talking to — use it when they ask you to '
                . 'commit as them, typically because Vercel or another deploy platform blocked a '
                . 'deployment for an unrecognised commit author. "default" goes back to your own name and '
                . GitIdentity::DEFAULT_EMAIL . '. You cannot set any other email; if someone needs one, '
                . 'tell them an admin must run kanvas:coding:git-identity. Affects the next push, not '
                . 'commits already pushed.',
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
                name: 'commit_as',
                type: PropertyType::STRING,
                description: '"me" for the person you are talking to, "default" to reset.',
                required: true,
                enum: ['me', 'default'],
            ),
            new ToolProperty(
                name: 'relaying_human_instruction',
                type: PropertyType::BOOLEAN,
                description: 'True only when the person in this conversation just asked for this change.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $commit_as = null, ?bool $relaying_human_instruction = null): array
    {
        if ($relaying_human_instruction !== true) {
            return $this->denied(
                'Changing whose name your commits carry needs the person to ask for it.',
                guidance: 'Ask them whether they want your commits authored as them, and call this again '
                    . 'once they say yes.'
            );
        }

        $action = new SetAgentGitIdentityAction($this->agent);

        if ($commit_as === 'default') {
            return $this->ok(
                ['commit_identity' => (string) $action->reset()],
                guidance: 'Say which identity the next push will use.'
            );
        }

        if ($commit_as !== 'me') {
            return $this->invalidArgs('commit_as must be "me" or "default".');
        }

        if (! $this->isHuman($this->requestingHuman)) {
            return $this->denied(
                'There is no signed-in person in this conversation to commit as.',
                guidance: 'This only works in a direct chat with the person. Otherwise an admin can run '
                    . 'kanvas:coding:git-identity with the email to use.'
            );
        }

        $human = $this->requestingHuman;

        try {
            // Never a null name: set() would keep whatever name was there before, pairing this person's
            // email with somebody else's name.
            $identity = $action->set(
                name: Str::trimToNull($human->firstname . ' ' . $human->lastname)
                    ?? Str::trimToNull($human->displayname)
                    ?? $human->email,
                email: $human->email,
            );
        } catch (ValidationException $e) {
            return $this->failed(
                $e->getMessage(),
                guidance: 'The identity was not changed. Report the reason as given.'
            );
        }

        return $this->ok(
            ['commit_identity' => (string) $identity],
            guidance: 'Say which identity the next push will use. A deploy platform only accepts it if '
                . 'this email is also on their GitHub account (GitHub → Settings → Emails) — mention that.'
        );
    }

    /**
     * On @mention and channel surfaces the turn's user can be the agent's own account; committing as
     * that would put the agent's identity back under a human-looking name.
     */
    private function isHuman(?Users $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->getId() !== $this->agent->user_id
            && $user->getId() !== $this->agent->company?->getAiAgentUser()?->getId();
    }
}
