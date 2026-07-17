<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionMethod;
use Tests\TestCase;
use Twilio\Exceptions\RestException;

/**
 * Regression for KANVAS-ECOSYSTEM-5R5: when a recipient texts STOP, Twilio
 * auto-unsubscribes them and rejects our reply with error 21610. The activity
 * retried 3× and each failure hit Sentry. sendResponse() must swallow 21610 as
 * an expected opt-out (and leave a note on the lead) while still surfacing every
 * other Twilio error.
 */
class AgentChannelResponderOptOutTest extends TestCase
{
    public function testUnsubscribedRecipientIsSwallowedAndRecordsLeadNote(): void
    {
        ['lead' => $lead, 'message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $action = new class ($channel, $message, $agent, null) extends AgentChannelResponderAction {
            protected function dispatchMessage(string $to, string $from, string $body): void
            {
                throw new RestException('Unable to create record: Attempt to send to unsubscribed recipient', 21610, 400);
            }
        };

        // Must not throw — the opt-out is expected and swallowed.
        new ReflectionMethod($action, 'sendResponse')
            ->invoke($action, '+17073383454', '+17076342748', 'Hi there, following up on your vehicle.');

        $noteRecorded = Message::query()
            ->where('companies_id', $lead->companies_id)
            ->where('message', 'like', '%opted out of messages%')
            ->exists();

        $this->assertTrue($noteRecorded, 'an opt-out note should be recorded on the lead');
    }

    public function testOtherTwilioErrorsAreRethrown(): void
    {
        $action = new class () extends AgentChannelResponderAction {
            public function __construct()
            {
            }

            protected function dispatchMessage(string $to, string $from, string $body): void
            {
                throw new RestException("The 'To' number is not a valid phone number", 21211, 400);
            }
        };

        $this->expectException(RestException::class);
        $this->expectExceptionCode(21211);

        new ReflectionMethod($action, 'sendResponse')
            ->invoke($action, '+17073383454', '+17076342748', 'reply body');
    }

    private function setupLeadMessageChannelAgent(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads']
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'Stop'],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = $message->fresh();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'twilio-test-' . $lead->getId(),
            ],
            [
                'name' => 'Test Twilio Channel',
                'description' => 'Test channel for opt-out logic',
                'users_id' => $user->getId(),
            ]
        );

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return compact('lead', 'message', 'channel', 'agent');
    }
}
