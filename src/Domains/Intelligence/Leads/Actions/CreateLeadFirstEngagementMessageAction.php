<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Actions;

use Illuminate\Support\Facades\Blade;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum as VoiceBridgeConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;

use function Laravel\Ai\agent;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\StructuredAgentResponse;
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
        protected Lead $lead,
        protected ?string $template = null
    ) {
        $this->agent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', AgentEnum::FIRST_MESSAGE_ENGAGER->value)
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
            'additional_context_information' => array_merge(
                $this->lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
                ['people' => $this->lead->people->toArray()],
                ['company' => $this->lead->company->toArray()],
                ['lead' => $this->lead->toArray()]
            ),
            'template' => $this->template,
            'ai_mode' => $this->lead->get(new LeadConfigurationService()->getAiModeKey($this->lead)),
            'follow_up_mode' => $this->lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
            'allow_call_appointments' => $this->lead->company->get(CompanyConfigurationEnum::ALLOW_CALL_APPOINTMENTS->value) ?? true,
        ];

        $data['leadOwnerEmail'] = $this->lead->owner?->email;
        $data['customerName'] = $this->lead->people->name;
        $data['leadEmail'] = $this->lead->people->getEmails()->first()?->value ?? '';
        $data['leadOwnerName'] = $this->lead->owner?->firstname . ' ' . $this->lead->owner?->lastname;
        $data['voice_enabled'] = ! empty($this->lead->app->get(VoiceBridgeConfigurationEnum::API_KEY->value));
        $data['available_channels'] = $this->resolveAvailableChannels();

        $prompt = Blade::render(
            implode(' ', $this->agent->role['steps']),
            $data['additional_context_information']
        );

        try {
            $response = $this->callAi($prompt);
        } catch (AiException $e) {
            $response = $this->callAi($prompt);
        }

        return [
            ...$response->structured,
            ['background' => $prompt],
        ];
    }

    protected function resolveAvailableChannels(): array
    {
        $channels = [];
        $hasPhone = ! empty($this->lead->people->getCellPhones()->first()?->value)
            || ! empty($this->lead->people->getAllPhones()->first()?->value);
        $hasEmail = ! empty($this->lead->people->getEmails()->first()?->value);
        $voiceEnabled = ! empty($this->lead->app->get(VoiceBridgeConfigurationEnum::API_KEY->value));

        if ($hasPhone) {
            $channels[] = 'sms';
            if ($voiceEnabled) {
                $channels[] = 'voice';
            }
        }

        if ($hasEmail) {
            $channels[] = 'email';
        }

        return $channels;
    }

    public function callAi(string $prompt): StructuredAgentResponse
    {
        /** @var StructuredAgentResponse $response */
        $response = agent(
            schema: fn ($schema) => [
                'title' => $schema->string()->description('The subject or title of the engagement message')->required(),
                'message' => $schema->string()->description('The main body of the engagement message')->required(),
            ],
        )->prompt(
            $prompt,
            provider: Lab::Gemini,
            model: 'gemini-2.5-pro',
            timeout: 220,
        );

        return $response;
    }
}
