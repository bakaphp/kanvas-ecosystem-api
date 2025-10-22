<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

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

    public function execute(): string
    {
        $config = $this->pipelineStage->config;
        $rules = $config['notification_engagement_rules'];

        $data = [
            'day' => $rules['day'],
            'templates' => $rules['templates'],
            'conversation_history' => $this->mapConversationHistory(),
            'context' => [
                'company' => $this->lead->company,
                'lead' => $this->lead,
                'lead_owner' => $this->lead->owner,
            ],
        ];

        $prompt = Blade::render(implode(' ', $this->agent->role['background']), $data);
        $responseText = $this->generateResponseWithRetry($prompt);

        $messageType = MessageType::firstOrCreate([
            'apps_id' => $this->session->apps_id,
            'languages_id' => 1,
            'name' => 'AI Generated Message',
        ]);

        $user = Users::getById($this->session->agent->user_id);
        $messageInput = MessageInput::from([
            'app' => $this->session->app,
            'company' => $this->session->company,
            'user' => $user,
            'type' => $messageType,
            'message' => $responseText,
        ]);

        $message = new CreateSocialMessageAction($messageInput)->execute();
        $this->session->channel->addMessage($message);

        return $responseText;
    }

    private function generateResponseWithRetry(string $prompt): string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            $response = Prism::text()
                ->using(Provider::Gemini, 'gemini-2.5-pro')
                ->withPrompt($prompt)
                ->asText();

            if (! empty($response->text)) {
                return $response->text;
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
