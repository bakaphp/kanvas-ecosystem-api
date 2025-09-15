<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Actions;

use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;

/**
 * Creates a structured first engagement message for a lead using AI.
 *
 * @example
 * $action = new CreateLeadFirstEngagementMessageAction($lead);
 * $response = $action->execute();
 * // Returns: ['title' => 'Subject line', 'message' => 'Message body']
 */
class CreateLeadFirstEngagementMessageAction
{
    protected Agent $agent;

    public function __construct(
        protected Lead $lead
    ) {
        $agentName = 'FirstMessagingAgent';
        $this->agent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    public function execute(): array
    {
        if (empty($this->agent->role['background']) || empty($this->agent->role['steps'])) {
            throw new RuntimeException('Agent background or steps are empty');
        }

        $data = [
            'lead' => $this->lead->toArray(),
            'people' => $this->lead->people->toArray(),
            'company' => $this->lead->company->toArray(),
        ];

        $data['leadOwnerEmail'] = $this->lead->owner?->email;
        $data['customerName'] = $this->lead->people->name;
        $data['leadEmail'] = $this->lead->people->getEmails()->first()?->value ?? '';
        $data['leadOwnerName'] = $this->lead->owner?->firstname . ' ' . $this->lead->owner?->lastname;

        // Define the schema for the structured response
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

        // Return the structured data containing title and message
        return $response->structured ?? [];
    }
}
