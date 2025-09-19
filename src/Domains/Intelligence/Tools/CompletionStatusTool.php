<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Override;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class CompletionStatusTool implements ContextToolInterface
{
    protected Agent $agent;

    public function __construct(protected Model $entity)
    {
        $agentName = 'CompletionStatusTool';
        $this->agent = Agent::fromApp($entity->app)
            ->fromCompany($entity->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $data = [
            'lead' => $this->entity->toArray(),
            'people' => $this->entity->people->toArray(),
            'company' => $this->entity->company->toArray(),
            'additional_context_information' => $this->entity->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
        ];

        $schema = new ObjectSchema(
            name: 'lead_quick',
            description: 'Lead intent status and evidence',
            properties: [
                new StringSchema(
                    name: 'lead_intent',
                    description: 'Echo of the intent passed as input'
                ),

                new EnumSchema(
                    name: 'intent_completion_status',
                    description: 'Whether the intent is completed',
                    options: ['COMPLETE', 'INCOMPLETE']
                ),

                new ArraySchema(
                    name: 'completion_evidence',
                    description: 'Short evidence strings from CRM/ADF/forms/notes/status/tags/integrations',
                    items: new StringSchema('evidence', 'Evidence string')
                ),

                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence score between 0.0 and 1.0'
                ),

                new StringSchema(
                    name: 'internal_notes',
                    description: 'One concise CRM note; no PII beyond artifacts'
                ),
            ],
            requiredFields: [
                'lead_intent',
                'intent_completion_status',
                'completion_evidence',
                'confidence',
                'internal_notes',
            ]
        );

        $response = Prism::structured()
                   ->using(Provider::Gemini, 'gemini-2.0-flash')
                   ->withSchema($schema)
                   ->withSystemPrompt(Blade::render(implode(' ', $this->agent->role['background']), $data))
                   ->withPrompt(Blade::render(implode(' ', ['Clasifica el lead según el esquema proporcionado.']), $data))
                    ->withMaxTokens(7000)
                   ->asStructured();

        return $response->structured;
    }
}
