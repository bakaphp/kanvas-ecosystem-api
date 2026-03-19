<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ContactCheckerTool implements ContextToolInterface
{
    protected Agent $agent;
    protected Model $lead;

    public function __construct(protected Message $message)
    {
        $this->lead = $this->getLeadFromMessage($message);
        $agentName = 'ContactCheckerAgent';
        $this->agent = Agent::fromApp($this->lead->app)
            ->fromCompany($this->lead->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    #[Override]
    public function execute(array $params = []): array
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

        $schema = new ObjectSchema(
            name: 'contact_check',
            description: 'Contact status analysis result',
            properties: [
                new BooleanSchema(
                    name: 'already_contacted',
                    description: 'Whether the lead has been contacted by a representative'
                ),
                new BooleanSchema(
                    name: 'should_send_first_message',
                    description: 'Whether a first message should be sent to the lead'
                ),
                new BooleanSchema(
                    name: 'note_is_relevant',
                    description: 'Whether the note is relevant to contact status determination'
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence score between 0.0 and 1.0'
                ),
                new StringSchema(
                    name: 'reason',
                    description: 'Brief explanation of the contact status determination'
                ),
                new StringSchema(
                    name: '_thought_process',
                    description: 'Internal reasoning and analysis process'
                ),
            ],
            requiredFields: [
                'already_contacted',
                'should_send_first_message',
                'note_is_relevant',
                'confidence',
                'reason',
                '_thought_process',
            ]
        );

        $response = Prism::structured()
            ->using(Provider::Gemini, 'gemini-2.5-pro')
            ->withSchema($schema)
            ->withSystemPrompt(Blade::render(implode(' ', $this->agent->role['background']), $data))
            ->withPrompt(Blade::render(implode('\n', $this->agent->role['steps']), $data))
            ->withMaxTokens(7000)
            ->asStructured();

        return $response->structured;
    }

    protected function getLeadFromMessage(Message $message): Model
    {
        $channel = $message->channels()
            ->where('name', 'Notes')
            ->first();

        if (! $channel || ! $channel->entity_namespace || ! $channel->entity_id) {
            throw new InvalidArgumentException('Message is not associated with a Notes channel linked to an entity');
        }

        $lead = $message->entity();

        return $lead;
    }
}
