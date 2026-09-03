<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\HandlesSupportModeDelayedResponseTrait;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Jobs\SendUnrespondedAgentMessageJob;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class SupportModeDelayedResponseToggleTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence'];

    public function testDelayedResponseDispatchesWhenAppHasNoOptOut(): void
    {
        Queue::fake();

        [$app, $company, $lead, $channel, $message, $agent] = $this->setUpSupportModeScenario();

        $result = $this->handler()->handle($lead, $channel, $message, $app, (int) $agent->getId());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('delay_minutes', $result);
        Queue::assertPushed(SendUnrespondedAgentMessageJob::class);
    }

    public function testDelayedResponseIsSkippedWhenAppOptsOut(): void
    {
        Queue::fake();

        [$app, $company, $lead, $channel, $message, $agent] = $this->setUpSupportModeScenario();
        $app->set(ConfigurationEnum::SUPPORT_MODE_DELAYED_RESPONSE->value, false);

        $result = $this->handler()->handle($lead, $channel, $message, $app, (int) $agent->getId());

        $this->assertNull($result);
        Queue::assertNotPushed(SendUnrespondedAgentMessageJob::class);

        $app->set(ConfigurationEnum::SUPPORT_MODE_DELAYED_RESPONSE->value, true);
    }

    private function handler(): object
    {
        return new class () {
            use HandlesSupportModeDelayedResponseTrait;

            public function handle(
                Lead $lead,
                Channel $channel,
                Message $message,
                Apps $app,
                int $agentId
            ): ?array {
                return $this->handleSupportModeDelayedResponse(
                    $lead,
                    $channel,
                    $message,
                    $app,
                    $agentId,
                    [],
                    null,
                    [],
                    'stub-action-class'
                );
            }
        };
    }

    private function setUpSupportModeScenario(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var Companies $company */
        $company = $user->getCurrentCompany();

        $company->timezone = 'UTC';
        $company->saveQuietly();
        $company->set(
            CompanyConfigurationEnum::WORKING_HOURS->value,
            array_fill_keys(
                [
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday',
                    'Friday',
                    'Saturday',
                    'Sunday',
                ],
                '00:00 - 23:59'
            )
        );

        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'support-mode-toggle-' . fake()->unique()->uuid(),
            ],
            [
                'name' => 'Support Mode Toggle',
                'description' => 'Support mode delayed response toggle',
                'users_id' => $user->getId(),
            ]
        );

        $humanMessage = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'content' => 'Let me check that for you',
                    'from_human' => true,
                ],
            ]);
        $channel->addMessage($humanMessage);

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'message' => [
                    'content' => 'Any update?',
                    'from_me' => false,
                ],
            ]);
        $channel->addMessage($inbound);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'Support Toggle Agent']);

        return [$app, $company, $lead->refresh(), $channel, $inbound, $agent];
    }
}
