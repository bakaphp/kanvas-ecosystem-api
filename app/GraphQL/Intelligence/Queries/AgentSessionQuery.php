<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Apps\Models\Apps;

class AgentSessionQuery
{
    public function getSessionInfo(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return [
            'id' => $request['id'],
            'name' => 'orchestrate',
            'company' => $user->getCurrentCompany(),
            'user' => $user,
            'company_config' => [
                'mcp_tools' => ['create_calendar_event', 'edit_calendar_event', '...'], // Validate tools confirmation
                'agents' => ['appointment_assist', 'document_validator', 'company_assist', '...'],
            ],
            'content' => [
                'origin' => 'trigger',
                'type' => 'google_calendar',
                'created_by' => '',
                'content' => [
                    'start_datetime' => '9/6/2025 11:00',
                    'duration' => '30 mins',
                    'title' => 'Q3 Marketing Campaign Review',
                    'invite' => [
                        'laura@example.com',
                        'carlos@example.com',
                    ],
                    'google_meet' => true,
                    'llm_message' => 'Please schedule a Google Calendar event for next Wednesday at 11:00 AM titled \'Q3 Marketing Campaign Review\' with laura@example.com and carlos@example.com, and generate a Google Meet link for the location.',
                ],
                'calendar_credentials' => '',
            ],
        ];
    }
}
