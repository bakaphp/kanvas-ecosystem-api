<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Baka\Support\Str;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Services\SlackConversationChannelService;
use Kanvas\Connectors\Slack\Services\SlackReceiverService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTeammates;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\LinksAskToTask;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Slack DM', category: 'ecosystem')]
class SendSlackDirectMessageTool extends Tool implements RequiresSystemAgent
{
    use FindsTeammates;
    use HasKanvasContext;
    use LinksAskToTask;

    protected string $name = 'send_slack_direct_message';

    protected ?string $description = 'Send a private direct message on Slack to a specific teammate, identified by their '
        . 'email address. Use it only when someone explicitly asks you to DM / privately message a '
        . 'named teammate on Slack. The recipient must be a member of this company — you cannot DM '
        . 'arbitrary people; only have a name? find_teammate first. With task_id, the message goes out as a group DM that also includes the '
        . 'person who asked for the task, so their reply reaches everyone. Still reply in the current '
        . 'conversation after sending.';

    public function __construct(
        private readonly ?Agent $agent = null,
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
                name: 'recipient_email',
                type: PropertyType::STRING,
                description: "The teammate's email address. Must belong to a user in this company.",
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The direct message to send, written as the agent speaking to the teammate.',
                required: true,
            ),
            new ToolProperty(
                name: 'task_id',
                type: PropertyType::INTEGER,
                description: 'When this message asks the teammate for something a task of yours is waiting on, the '
                    . 'id of that task. It records them as the person who owes the answer, so their reply '
                    . 'reaches you with the task in hand. Then snooze_task to when they said.',
                required: false,
            ),
            new ToolProperty(
                name: 'waiting_for',
                type: PropertyType::STRING,
                description: 'When this message asks the teammate for something and there is NO task yet: what you '
                    . 'are waiting on, e.g. "the Creza report". A follow-up task is opened for the person you are '
                    . 'talking to, parked on the teammate, and you are woken on `until` to chase it. Omit when you '
                    . 'passed task_id, or when the message asks for nothing.',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'With waiting_for: when the teammate said they would answer, as "YYYY-MM-DD HH:MM" in '
                    . 'the company\'s local time. Empty means tomorrow at 09:00.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $recipient_email,
        string $message,
        ?int $task_id = null,
        ?string $waiting_for = null,
        ?string $until = null,
    ): array {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No Slack agent is in scope, so I cannot send a Slack DM.'];
        }

        $recipient_email = trim($recipient_email);
        $message = trim($message);

        if ($recipient_email === '' || $message === '') {
            return ['status' => 'error', 'message' => 'Both a recipient email and a message are required.'];
        }

        $recipient = $this->teammateByEmail($recipient_email, $this->agent);

        if ($recipient === null) {
            return [
                'status' => 'error',
                'message' => "No teammate in this company has the email {$recipient_email}. Ask the user to confirm who to message.",
            ];
        }

        $waiting_for = Str::trimToNull($waiting_for);
        $task = $this->askTask($task_id, $this->agent);
        $asker = $this->askerToInclude($task, $recipient, opensFollowUp: $waiting_for !== null);

        try {
            $client = Client::getInstanceByAgent($this->agent);

            $slackUserId = $client->lookupUserIdByEmail($recipient->email);
            if ($slackUserId === null) {
                return [
                    'status' => 'error',
                    'message' => "{$recipient->email} is a teammate but isn't in the connected Slack workspace, so I can't DM them there.",
                ];
            }

            $askerSlackId = $asker !== null ? $client->lookupUserIdByEmail($asker->email) : null;
            $slackChannelId = $client->openDirectMessageChannel(...array_filter([$slackUserId, $askerSlackId]));
            $client->postMarkdownMessage($slackChannelId, $message);
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => 'Slack could not deliver the DM: ' . $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'The Slack DM could not be sent right now. Tell the user you will follow up.'];
        }

        $result = [
            'status' => 'success',
            'to' => $recipient->email,
            'message' => "Direct message sent to {$recipient->email} on Slack.",
        ];

        if ($task_id === null && $waiting_for === null) {
            return $result;
        }

        if ($task_id !== null && $task === null) {
            return [...$result, ...$this->taskNotFound($task_id)];
        }

        return [
            ...$result,
            ...$this->groupDmNote($asker, $askerSlackId),
            ...$this->recordAsk(
                $task_id,
                $waiting_for,
                $until,
                $this->agent,
                $recipient,
                $this->contextUser(),
                $this->session,
                $this->fileInKanvasChannel($slackChannelId, $message)?->getId(),
            ),
        ];
    }

    /**
     * An ask made for a task goes out in front of the person who wanted the answer, so a plain reply
     * reaches both of them and nobody has to be told what was said. For a task that is the plan's
     * recorded asker; for a follow-up being opened it is the person in this conversation. Nobody to
     * include when there is no such person, or they are the recipient or the agent itself.
     */
    private function askerToInclude(?Task $task, Users $recipient, bool $opensFollowUp): ?Users
    {
        $asker = $task?->plan?->asker() ?? ($opensFollowUp ? $this->contextUser() : null);

        if ($asker === null || $asker->getId() === $recipient->getId() || $asker->getId() === $this->agent?->user?->getId()) {
            return null;
        }

        return $asker;
    }

    /**
     * @return array<string, string>
     */
    private function groupDmNote(?Users $asker, ?string $askerSlackId): array
    {
        if ($asker === null) {
            return [];
        }

        return $askerSlackId !== null
            ? ['group_dm_with' => $asker->email]
            : ['note' => "{$asker->email} asked for this but is not in the Slack workspace, so it went as a one-to-one DM."];
    }

    /**
     * The same conversation Slack's reply will land in, so the wake can say "re-read channel N" and
     * find the ask there beside the answer. Best-effort: the DM is already delivered.
     */
    private function fileInKanvasChannel(string $slackChannelId, string $message): ?Channel
    {
        if ($this->agent === null || $this->agent->user === null) {
            return null;
        }

        $author = $this->agent->user;
        $teamId = new SlackReceiverService()->teamIdFor($this->agent);

        if ($teamId === null) {
            return null;
        }

        try {
            $channel = new SlackConversationChannelService()->channelFor(
                $this->agent,
                $author,
                $teamId,
                $slackChannelId,
            );

            new PostChannelMessageAction(
                channel: $channel,
                author: $author,
                verb: 'slack',
                content: $message,
                extraPayload: [
                    'from_ia' => true,
                    'agent_id' => $this->agent->getId(),
                    'slack_channel' => $slackChannelId,
                ],
            )->execute();

            return $channel;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
