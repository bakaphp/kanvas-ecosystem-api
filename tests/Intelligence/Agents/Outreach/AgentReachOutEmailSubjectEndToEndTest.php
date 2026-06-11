<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use ReflectionProperty;
use Tests\Stubs\Intelligence\SalesEmailEnvelopeNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * Email reach-out: the agent returns a `{subject, response}` envelope. We verify the
 * subject lands on the outgoing mail (not the generic "Message from {company}" fallback)
 * and the persisted body is clean — no `Subject:` line leaking into the content.
 *
 * Guards the two bugs fixed together: (1) subject buried in the body, (2) body
 * duplicated by the old join-every-JSON-field response extractor.
 */
class AgentReachOutEmailSubjectEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    public function testEmailReachOutLiftsSubjectToMailTitleAndKeepsBodyClean(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'alan@kanvas.dev',
            'weight' => 1,
        ]);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales Email (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesEmailEnvelopeNeuronAgentStub::class,
            ]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally Outreach',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Return the structured email envelope',
                'output_format' => 'json',
            ]);

        try {
            new AgentReachOutOnChannelAction(
                lead: $lead,
                agent: $agent,
                channelType: 'email',
                recipient: 'alan@kanvas.dev',
            )->execute();
        } catch (Throwable) {
            // Any post-send hook may throw in the test env; persistence + the faked
            // mail dispatch run first, which is what we assert below.
        }

        // The mail goes out with the agent's subject — NOT the generic fallback.
        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'alan@kanvas.dev') {
                    return false;
                }

                $subjectProp = new ReflectionProperty($notification, 'subject');

                return $subjectProp->getValue($notification) === SalesEmailEnvelopeNeuronAgentStub::SUBJECT;
            }
        );

        // The persisted outbound body is the clean envelope body — no subject line,
        // and it appears exactly once (regression guard for the duplication bug).
        $outbound = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound);
        $content = (string) ($outbound->message['content'] ?? '');
        $this->assertSame(SalesEmailEnvelopeNeuronAgentStub::BODY, $content);
        $this->assertStringNotContainsString('Subject:', $content);
        $this->assertSame(1, substr_count($content, "Let's talk."), 'Body must not be duplicated');

        // Part 2 guard: onNewMessage no longer writes the assistant content row, so the
        // raw `{"subject":...,"response":...}` envelope must NOT appear anywhere on the
        // People timeline — only the canonical, extracted outbound body.
        $peopleContents = AppModuleMessage::query()
            ->where('system_modules', People::class)
            ->where('entity_id', $lead->people->getId())
            ->where('apps_id', $app->getId())
            ->with('message')
            ->get()
            ->map(fn (AppModuleMessage $row): string => (string) ($row->message?->message['content'] ?? ''));

        foreach ($peopleContents as $persisted) {
            $this->assertStringNotContainsString(
                '"response"',
                $persisted,
                'Raw agent JSON envelope must not be persisted (onNewMessage double-write removed)',
            );
        }
    }
}
