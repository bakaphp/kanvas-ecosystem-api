<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

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
            'data' => [
                'day' => $rules['day'],
                'templates' => $rules['templates'],
                'conversation_history' => $this->mapConversationHistory(),
                'context' => $this->session->content,
            ],
        ];

        $prompt = Blade::render(implode(' ', $this->agent->role['steps']), $data);
        $response = Prism::text()
            ->using(Provider::Gemini, 'gemini-2.5-pro')
            ->withPrompt($prompt)
            ->asText();

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
            'message' => $response->text,
        ]);
        $message = new CreateSocialMessageAction($messageInput)->execute();
        $this->session->channel->addMessage($message);

        return $response->text;
    }

    public function mapConversationHistory(): array
    {
        $messages = $this->session->channel->messages()->orderBy('created_at', 'asc')->get();
        $conversationHistory = [];

        foreach ($messages as $message) {
            $conversationHistory[] = [
                'user' => $message->users_id === $this->session->agent->user_id ? 'agent' : 'lead',
                'message' => $message->message,
            ];
        }

        return $conversationHistory;
    }
}
