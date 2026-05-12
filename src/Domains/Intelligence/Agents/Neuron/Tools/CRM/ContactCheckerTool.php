<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Messages\Models\Message;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use NeuronAI\Tools\Tool;

use function Laravel\Ai\agent;

class ContactCheckerTool extends Tool
{
    protected Agent $agent;
    protected Model $lead;

    public function __construct(protected Message $message)
    {
        parent::__construct(
            name: 'check_contact_status',
            description: 'Analyze if the lead has already been contacted based on a note or message, returning contact status and confidence.',
        );

        $this->lead = $this->getLeadFromMessage($message);
        $agentName = 'ContactCheckerAgent';
        $this->agent = Agent::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    public function __invoke(): array
    {
        $noteContent = $this->message->message;

        $data = [
            'note' => $noteContent,
            'context' => [
                'lead_id' => (string) $this->lead->getId(),
                'dealership_name' => $this->lead->company->name,
                'lead_name' => $this->lead->title ?? $this->lead->people?->name ?? 'Unknown',
            ],
        ];

        /** @var StructuredAgentResponse $response */
        $response = agent(
            instructions: Blade::render(implode(' ', $this->agent->role['background']), $data),
            schema: fn ($schema) => [
                'already_contacted' => $schema->boolean()->description('Whether the lead has been contacted by a representative')->required(),
                'should_send_first_message' => $schema->boolean()->description('Whether a first message should be sent to the lead')->required(),
                'note_is_relevant' => $schema->boolean()->description('Whether the note is relevant to contact status determination')->required(),
                'confidence' => $schema->number()->description('Confidence score between 0.0 and 1.0')->required(),
                'reason' => $schema->string()->description('Brief explanation of the contact status determination')->required(),
                '_thought_process' => $schema->string()->description('Internal reasoning and analysis process')->required(),
            ],
        )->prompt(
            Blade::render(implode('\n', $this->agent->role['steps']), $data),
            provider: Lab::Gemini,
            model: 'gemini-2.5-pro',
        );

        return $response->structured;
    }

    protected function getLeadFromMessage(Message $message): Model
    {
        $channel = $message->channels()
            ->where('name', ChannelNameEnum::NOTES->value)
            ->first();

        if (! $channel || ! $channel->entity_namespace || ! $channel->entity_id) {
            throw new InvalidArgumentException('Message is not associated with a Notes channel linked to an entity');
        }

        $lead = $message->entity();

        return $lead;
    }
}
