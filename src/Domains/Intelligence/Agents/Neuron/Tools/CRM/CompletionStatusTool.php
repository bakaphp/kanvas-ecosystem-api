<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

use function Laravel\Ai\agent;

#[AgentTool(name: 'Completion Status')]
class CompletionStatusTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_lead_completion_status',
            description: 'Analyze and return the completion status of the lead intent using AI, '
                . 'including evidence and confidence score. '
                . 'Use this to determine if the lead has completed their intended goal.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $lead = Lead::getById($lead_id);

        $neuronAgent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', 'CompletionStatusTool')
            ->firstOrFail();

        $data = [
            'lead' => $lead->toArray(),
            'people' => $lead->people->toArray(),
            'company' => $lead->company->toArray(),
            'additional_context_information' => $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
        ];

        /** @var StructuredAgentResponse $response */
        $response = agent(
            instructions: Blade::render(implode(' ', $neuronAgent->role['background']), $data),
            schema: fn ($schema): array => [
                'lead_intent' => $schema->string()->description('Echo of the intent passed as input')->required(),
                'intent_completion_status' => $schema->string()->enum(['COMPLETE', 'INCOMPLETE'])->description('Whether the intent is completed')->required(),
                'completion_evidence' => $schema->array()
                    ->items($schema->string()->description('Evidence string'))
                    ->description('Short evidence strings from CRM/ADF/forms/notes/status/tags/integrations')
                    ->required(),
                'confidence' => $schema->number()->description('Confidence score between 0.0 and 1.0')->required(),
                'internal_notes' => $schema->string()->description('One concise CRM note; no PII beyond artifacts')->required(),
            ],
        )->prompt(
            Blade::render(implode('\n', $neuronAgent->role['steps']), $data),
            provider: Lab::Gemini,
            model: 'gemini-2.5-pro',
        );

        return $response->structured;
    }
}
