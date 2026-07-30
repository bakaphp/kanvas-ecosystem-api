<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Send Slack DM', category: 'ecosystem')]
class SendSlackDirectMessageTool extends Tool
{
    public function __construct(private readonly ?Agent $agent = null)
    {
        parent::__construct(
            name: 'send_slack_direct_message',
            description: 'Send a private direct message on Slack to a specific teammate, identified by their '
                . 'email address. Use it only when someone explicitly asks you to DM / privately message a '
                . 'named teammate on Slack. The recipient must be a member of this company — you cannot DM '
                . 'arbitrary people. Still reply in the current conversation after sending.',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $recipient_email, string $message): array
    {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No Slack agent is in scope, so I cannot send a Slack DM.'];
        }

        $recipient_email = trim($recipient_email);
        $message = trim($message);

        if ($recipient_email === '' || $message === '') {
            return ['status' => 'error', 'message' => 'Both a recipient email and a message are required.'];
        }

        try {
            $recipient = UsersRepository::getUserOfAppByEmail($recipient_email, $this->agent->app);
            UsersRepository::belongsToCompany($recipient, $this->agent->company);
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No teammate in this company has the email {$recipient_email}. Ask the user to confirm who to message.",
            ];
        }

        try {
            $client = Client::getInstanceByAgent($this->agent);

            $slackUserId = $client->lookupUserIdByEmail($recipient->email);
            if ($slackUserId === null) {
                return [
                    'status' => 'error',
                    'message' => "{$recipient->email} is a teammate but isn't in the connected Slack workspace, so I can't DM them there.",
                ];
            }

            $client->postMessage($client->openDirectMessageChannel($slackUserId), $message);
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => 'Slack could not deliver the DM: ' . $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => 'The Slack DM could not be sent right now. Tell the user you will follow up.'];
        }

        return [
            'status' => 'success',
            'to' => $recipient->email,
            'message' => "Direct message sent to {$recipient->email} on Slack.",
        ];
    }
}
