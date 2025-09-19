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

class LeadIntentTool implements ContextToolInterface
{
    protected Agent $agent;

    public function __construct(
        protected Model $entity
    ) {
        $agentName = 'LeadIntentTool';
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
        $vehicleOfInterest = new ObjectSchema(
            name: 'vehicle_interest',
            description: 'Vehicle the customer is interested in',
            properties: [
                new StringSchema('year', 'Model year', nullable: true),
                new StringSchema('make', 'Make/brand', nullable: true),
                new StringSchema('model', 'Model name', nullable: true),
                new StringSchema('trim', 'Trim name', nullable: true),
                new StringSchema('stock_id', 'Dealer stock identifier', nullable: true),
                new StringSchema('vin', 'Vehicle VIN', nullable: true),
            ],
            // Strict-mode friendly: presente pero puede ser null cada campo
            requiredFields: ['year','make','model','trim','stock_id','vin']
        );

        $engagementContext = new ObjectSchema(
            name: 'engagement_context',
            description: 'Context for how/when the lead engaged',
            properties: [
                new EnumSchema(
                    name: 'channel',
                    description: 'Inbound channel',
                    options: ['SMS', 'EMAIL', 'CHAT']
                ),
                new EnumSchema(
                    name: 'work_hours_status',
                    description: 'Whether contact occurred during business hours',
                    options: ['WORK_HOURS', 'AFTER_HOURS']
                ),
                new StringSchema('holiday_name', 'If after-hours due to a holiday, which one', nullable: true),
                new StringSchema('timezone', 'IANA timezone (e.g., America/Santo_Domingo)', nullable: true),
            ],
            requiredFields: ['channel','work_hours_status','holiday_name','timezone']
        );

        $leadSchema = new ObjectSchema(
            name: 'lead',
            description: 'Normalized auto lead intent payload',
            properties: [
                new EnumSchema(
                    name: 'lead_intent',
                    description: 'Primary intent detected for the lead',
                    options: [
                        'BOOK_TEST_DRIVE','VALUE_MY_TRADE','GET_PRE_QUALIFIED','APPLY_FOR_FINANCING',
                        'CALCULATE_PAYMENT','GET_PRICE_AND_CONFIRM_AVAILABILITY','REQUEST_MORE_INFO',
                        'ASK_ABOUT_VEHICLE_FEATURES','SCHEDULE_DELIVERY','SPEAK_TO_A_REP',
                    ]
                ),

                new ArraySchema(
                    name: 'intent_triggers_detected',
                    description: 'Keywords/phrases that triggered the intent',
                    items: new StringSchema('trigger', 'Trigger phrase/string')
                ),

                new EnumSchema(
                    name: 'intent_completion_status',
                    description: 'Whether the intent is fully satisfied',
                    options: ['COMPLETE','INCOMPLETE']
                ),

                new ArraySchema(
                    name: 'completion_evidence',
                    description: 'Short evidence strings supporting the status',
                    items: new StringSchema('evidence', 'Evidence snippet')
                ),

                $vehicleOfInterest,
                $engagementContext,

                new ArraySchema(
                    name: 'customer_questions',
                    description: 'Customer’s normalized questions (if any)',
                    items: new StringSchema('question', 'A single question'),
                    nullable: true
                ),

                new StringSchema('next_step', 'Clear next action based on intent & status'),
                new NumberSchema('confidence', 'Classifier confidence between 0.0 and 1.0'),
                new StringSchema('internal_notes', 'One-line CRM note; no PII beyond lead artifacts'),
            ],

            // Para “strict mode” (p. ej., OpenAI): todos requeridos; usa `nullable: true` donde aplica
            requiredFields: [
                'lead_intent',
                'intent_triggers_detected',
                'intent_completion_status',
                'completion_evidence',
                'vehicle_interest',
                'engagement_context',
                'customer_questions',
                'next_step',
                'confidence',
                'internal_notes',
            ]
        );
        $response = Prism::structured()
                   ->using(Provider::Gemini, 'gemini-2.5-flash')
                   ->withSchema($leadSchema)
                   ->withSystemPrompt(Blade::render(implode(' ', $this->agent->role['background']), $data))
                   ->withPrompt(Blade::render(implode('\n', $this->agent->role['steps']), $data))
                   ->asStructured();

        return $response->structured;
    }
}
