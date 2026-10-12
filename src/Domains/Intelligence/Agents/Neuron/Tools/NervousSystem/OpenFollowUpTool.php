<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTeammates;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\LinksAskToTask;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The wait without the message: the person asked someone themselves, or will, and only wants the
 * agent to keep track and chase on the date. Same follow-up plan and task the send tools open, so
 * the reply matching and the wake work the same way.
 */
#[AgentTool(name: 'Open Follow-up', category: 'nervous_system')]
class OpenFollowUpTool extends Tool implements RequiresSystemAgent
{
    use FindsTeammates;
    use HasKanvasContext;
    use LinksAskToTask;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'open_follow_up';

    protected ?string $description = 'Start tracking something the person you are talking to is waiting for, WITHOUT '
        . 'sending anyone a message: a follow-up task is opened for them, parked on the teammate who owes '
        . 'it when you name one, and you are woken on the date to chase it. Use it when they will ask '
        . 'the teammate themselves, or already did. When you are the one asking, use send_email_to_user '
        . 'or send_slack_direct_message with waiting_for instead — they open the same follow-up.';

    public function __construct(
        private readonly ?Session $session = null,
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
                name: 'waiting_for',
                type: PropertyType::STRING,
                description: 'What is being waited for, e.g. "the Creza report".',
                required: true,
            ),
            new ToolProperty(
                name: 'waiting_on_email',
                type: PropertyType::STRING,
                description: 'The email of the teammate who owes it, when there is one (find_teammate turns a name '
                    . 'into it). Their next message to you then arrives with this task in hand.',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'When to chase, as "YYYY-MM-DD HH:MM" in the company\'s local time. Empty means tomorrow '
                    . 'at 09:00.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $waiting_for, ?string $waiting_on_email = null, ?string $until = null): array
    {
        $waitingFor = Str::trimToNull($waiting_for);

        if ($waitingFor === null) {
            return $this->invalidArgs('Say what is being waited for.');
        }

        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('open a follow-up');
        }

        $agent = $this->contextAgent();

        if ($agent === null) {
            return $this->failed('No agent is in scope, so the follow-up cannot be opened.');
        }

        $email = Str::trimToNull($waiting_on_email);
        $recipient = $email !== null ? $this->teammateByEmail($email, $agent) : null;
        $warning = $email !== null && $recipient === null
            ? ['waiting_on_warning' => "No teammate in this company has the email {$email}; the follow-up was opened without recording who it waits on."]
            : [];

        $opened = $this->openFollowUp(
            $agent,
            $recipient,
            $waitingFor,
            $until,
            $this->user,
            $this->session,
            $this->session?->channel_id,
        );

        if (! isset($opened['task_id'])) {
            return $this->failed((string) ($opened['waiting_for_warning'] ?? 'The follow-up could not be opened.'));
        }

        $guidance = (string) $opened['guidance'];
        unset($opened['guidance']);

        return $this->ok([...$opened, ...$warning], guidance: $guidance);
    }
}
