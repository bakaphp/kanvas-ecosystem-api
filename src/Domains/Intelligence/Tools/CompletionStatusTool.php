<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Override;

use function Laravel\Ai\agent;

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
        $background = is_array($this->agent->role['background']) ? implode(' ', $this->agent->role['background']) : $this->agent->role['background'];
        /** @var StructuredAgentResponse $response */
        $response = agent(
            instructions: Blade::render($background, $data),
            schema: fn ($schema) => [
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
            Blade::render(implode('\n', $this->agent->role['steps']), $data),
            provider: Lab::Gemini,
            model: 'gemini-2.5-pro',
            timeout: 220,
        );

        return $response->structured;
    }
}
