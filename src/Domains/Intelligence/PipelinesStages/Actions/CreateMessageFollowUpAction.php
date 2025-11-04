<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class CreateMessageFollowUpAction
{
    protected Agent $agent;

    private const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(
        protected ModelsLead $lead,
        protected PipelineStage $pipelineStage,
        protected Session $session,
    ) {
        $agentName = 'FollowUpEngagerAgent';
        $this->agent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    public function execute(): ?string
    {
        $config = $this->pipelineStage->config;
        $rules = $config['notification_engagement_rules'];
        $companyWorkHour = new CompanyWorkHoursTool($this->lead)->execute();
        $data = [
            'day' => $rules['day'],
            'templates' => $rules['templates'],
            'conversation_history' => $this->mapConversationHistory(),
            'context' => [
                'company' => $this->lead->company,
                'lead' => $this->lead,
                'lead_owner' => $this->lead->owner,
            ],
            'work_hours_status' => $companyWorkHour,
            'is_engagement' => $this->lead->get(ConfigurationEnum::IS_ENGAGEMENT->value) ? 1 : 0,
            'holiday_status' => new CompanyIsHolidayTool($this->lead)->execute(),
        ];

        $prompt = Blade::render(implode(' ', $this->agent->role['background']), $data);
        $responseText = $this->generateResponseWithRetry($prompt);
        if (! $responseText['should_respond']) {
            return null;
        }
        $messageType = MessageType::firstOrCreate([
            'apps_id' => $this->session->apps_id,
            //'languages_id' => 1,
            //'name' => 'AI Generated Message',
            'name' => 'twilio-sms',
            'verb' => 'twilio-sms',
        ]);

        $user = Users::getById($this->session->agent->user_id);
        $message = $responseText['message'];
        $messageInput = MessageInput::from([
            'app' => $this->session->app,
            'company' => $this->session->company,
            'user' => $user,
            'type' => $messageType,
            'message' => [
                'content' => $message,
                'raw_data' => $message,
                'message_id' => '--',
                'chat_jid' => '--',
                'from_me' => true,
            ],
           'is_public' => 1,
        ]);

        $message = new CreateSocialMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName(
                get_class($this->lead),
                $this->lead->app
            ),
            $this->lead->getId(),
        )->execute();
        $this->session->channel->addMessage($message);

        return $responseText['message'];
    }

    private function generateResponseWithRetry(string $prompt): array
    {
        $schema = new ObjectSchema(
            name: 'follow_up_message',
            description: 'Lead message for follow up',
            properties: [
                new StringSchema(
                    name: 'message',
                    description: ' Message for the lead'
                ),
                new BooleanSchema(
                    name: 'should_respond',
                    description: 'Confirmation if must sent message'
                ),
                ],
            requiredFields: [
                    'message',
                    'should_respond',
                ]
        );
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            $response = Prism::structured()
                       ->using(Provider::Gemini, 'gemini-2.5-flash')
                       ->withSchema($schema)
                       ->withPrompt($prompt)
                       ->withMaxTokens(7000)
                       ->asStructured();
            if (! empty($response->structured)) {
                return $response->structured;
            }
        }

        throw new Exception(
            sprintf(
                'Failed to generate message response after %d attempts. No valid response received from AI.',
                self::MAX_RETRY_ATTEMPTS
            )
        );
    }

    public function mapConversationHistory(): array
    {
        $messages = $this->session->channel->messages()->orderBy('created_at', 'asc')->get();
        $conversationHistory = [];

        foreach ($messages as $message) {
            $conversationHistory[] = [
                'user' => $message->slug ? 'lead' : 'agent',
                'message' => $message->message,
            ];
        }

        return $conversationHistory;
    }
}
