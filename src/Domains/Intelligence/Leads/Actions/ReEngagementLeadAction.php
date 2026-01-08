<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ReEngagementLeadAction
{
    protected Agent $agent;

    public function __construct(
        protected Channel $channel,
        protected int $minutesWithoutActivity
    ) {
        $agentName = 'ReEngagementAgent';
        $this->agent = Agent::fromApp($channel->app)
            ->fromCompany($channel->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    public function execute(): void
    {
        $lastMessage = $this->channel->getLastMessage();
        if (! $lastMessage) {
            return;
        }
        $timezone = $this->channel->company->timezone ?? 'UTC';
        $lastMessageTime = $lastMessage->created_at->setTimezone($timezone);
        $currentTime = Carbon::now($timezone);
        $differenceInMinutes = $currentTime->diffInMinutes($lastMessageTime);
        if ($this->minutesWithoutActivity <= $differenceInMinutes) {
            $schema = new ObjectSchema(
                name: 're_engagement_lead_message',
                description: 'Re-engagement message for inactive leads',
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

            $companyWorkHour = new CompanyWorkHoursTool($this->channel->entity())->execute();
            $vehicleInterestTool = new VehicleInterestTool($this->channel->entity());

            $data = [
                'conversation_history' => $this->channel->mapLeadConversationHistory(),
                'context' => [
                    'company' => $this->channel->company,
                    'lead' => $this->channel->entity(),
                    'lead_owner' => $this->channel->entity()->owner,
                ],
                'work_hours_status' => $companyWorkHour,
                'is_engagement' => $this->channel->entity()->get(ConfigurationEnum::IS_ENGAGEMENT->value) ? 1 : 0,
                'holiday_status' => new CompanyIsHolidayTool($this->channel->entity())->execute(),
                'vehicle_interest' => $vehicleInterestTool,
            ];

            $prompt = Blade::render(implode(' ', $this->agent->role['background']), $data);

            $response = Prism::structured()
                       ->using(Provider::Gemini, 'gemini-2.5-pro')
                       ->withSchema($schema)
                       ->withPrompt($prompt)
                       ->withMaxTokens(7000)
                       ->asStructured();

            if (! empty($response->structured)) {
                $communicationChannel = ChannelCategoryEnum::getLeadChannelName($this->channel->slug);
                new SendMessageToLeadAction($this->channel->entity())->execute(
                    $communicationChannel,
                    $response->structured['message'],
                    $this->channel->company->get('twilio_phone_number')
                );
            }
        }
    }
}
