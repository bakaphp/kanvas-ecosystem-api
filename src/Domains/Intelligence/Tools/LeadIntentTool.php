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
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class LeadIntentTool implements ContextToolInterface
{
    protected Agent $agent;

    public function __construct(
        protected Model $entity
    ) {
        $agentName = 'firstMessageEngagerAgent';
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
            'context_info' => $this->entity->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
        ];

        $schema = new ObjectSchema(
            name: 'lead_engagement_message',
            description: 'First engagement message structure for a lead',
            properties: [
                        new StringSchema('title', 'The subject or title of the engagement message'),
                        new StringSchema('message', 'The main body of the engagement message'),
                    ],
            requiredFields: ['title', 'message']
        );
        $schema = new ObjectSchema(
            name: 'lead_engagement_message',
            description: 'First engagement message structure for a lead',
            properties: [
                        new StringSchema('title', 'The subject or title of the engagement message'),
                        new StringSchema('message', 'The main body of the engagement message'),
                    ],
            requiredFields: ['title', 'message']
        );

        $response = Prism::structured()
                   ->using(Provider::Gemini, 'gemini-2.0-flash')
                   ->withSchema($schema)
                   ->withSystemPrompt(Blade::render(implode(' ', $this->agent->role['background']), $data))
                   ->withPrompt(Blade::render(implode(' ', $this->agent->role['steps']), $data))
                   ->asStructured();

        return $data;
    }
}
