<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use App\Console\Commands\Intelligence\Messaging\SendDelayMessageCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Stubs\Intelligence\SalesEmailEnvelopeNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * The reach-out passes signature: false, which hides the persona block in first-time-agent-engagement.
 * A company that has first-time-agent-reach-out gets that template instead, signed unconditionally.
 */
final class AgentReachOutSignatureTemplateTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'ecosystem'];

    private const string PERSONA_SIGNATURE = 'Sally<br>Virtual Sales Assistant';

    public function testReachOutEmailUsesTheCompanyReachOutTemplateAndShowsTheSignature(): void
    {
        $lead = $this->makeLead();
        $this->makeCompanyTemplates($lead, withReachOut: true);
        Notification::fake();

        $this->reachOut($lead);

        $sent = $this->sentBlank();
        $this->assertSame(AgentReachOutConfigEnum::EMAIL_TEMPLATE, $sent->getTemplateName());
        $this->assertStringContainsString(self::PERSONA_SIGNATURE, $sent->getEmailContent());
    }

    public function testReachOutFallsBackToTheEngagementTemplateWhenTheCompanyHasNoReachOutTemplate(): void
    {
        $lead = $this->makeLead();
        $this->makeCompanyTemplates($lead, withReachOut: false);
        Notification::fake();

        $this->reachOut($lead);

        $sent = $this->sentBlank();
        $this->assertSame('first-time-agent-engagement', $sent->getTemplateName());
        $this->assertStringNotContainsString(self::PERSONA_SIGNATURE, $sent->getEmailContent());
    }

    public function testDelayedReachOutEmailUsesTheReachOutTemplate(): void
    {
        $lead = $this->makeLead();
        $this->makeCompanyTemplates($lead, withReachOut: true);
        Notification::fake();

        $user = auth()->user();
        MessageType::firstOrCreate(
            ['apps_id' => $lead->app->getId(), 'languages_id' => 1, 'verb' => 'mailgun-email'],
            ['name' => 'Mailgun Email']
        );
        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $lead->app->getId(),
                'companies_id' => $lead->company->getId(),
                'slug' => 'delayed-reach-out-' . fake()->unique()->uuid(),
            ],
            [
                'name' => 'Delayed Reach Out',
                'description' => 'Delayed reach-out test channel',
                'users_id' => $user->getId(),
            ]
        );
        $message = new PostChannelMessageAction(
            channel: $channel,
            author: $user,
            verb: 'mailgun-email',
            content: 'Thanks for your interest.',
            messageTypeName: 'Mailgun Email',
        )->execute();
        $message->addTags(['agent-reach-out', 'agent-reach-out-delayed'], $lead->app, $user, $lead->company);
        $message->set('communicationChannel', 'email');
        $message->set('title', 'Your inquiry');

        $command = new SendDelayMessageCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));
        new ReflectionMethod(SendDelayMessageCommand::class, 'sendDelayedMessage')
            ->invoke($command, $lead, $message, 'Thanks for your interest.');

        $sent = $this->sentBlank();
        $this->assertSame(AgentReachOutConfigEnum::EMAIL_TEMPLATE, $sent->getTemplateName());
        $this->assertStringContainsString(self::PERSONA_SIGNATURE, $sent->getEmailContent());
    }

    public function testRegularLeadEmailKeepsTheEngagementTemplateAndItsConditionalSignature(): void
    {
        $lead = $this->makeLead();
        $this->makeCompanyTemplates($lead, withReachOut: true);
        Notification::fake();

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: 'email',
            message: 'Following up on your visit.',
            title: 'Your visit',
            signature: false,
        );

        $sent = $this->sentBlank();
        $this->assertSame('first-time-agent-engagement', $result['template']);
        $this->assertSame('first-time-agent-engagement', $sent->getTemplateName());
        $this->assertStringNotContainsString(self::PERSONA_SIGNATURE, $sent->getEmailContent());
    }

    private function makeLead(): Lead
    {
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
            'value' => 'prospect@kanvas.dev',
            'weight' => 1,
        ]);

        return $lead;
    }

    /**
     * Same shape as the dealer templates: engagement signs only when asked, reach-out always does.
     */
    private function makeCompanyTemplates(Lead $lead, bool $withReachOut): void
    {
        $templates = [
            'first-time-agent-engagement' => '<div>{{ content|raw }}</div>'
                . '{% if signature is defined and signature == true %}<p>' . self::PERSONA_SIGNATURE . '</p>{% endif %}',
        ];

        if ($withReachOut) {
            $templates[AgentReachOutConfigEnum::EMAIL_TEMPLATE] = '<div>{{ content|raw }}</div>'
                . '<p>' . self::PERSONA_SIGNATURE . '</p>';
        }

        foreach ($templates as $name => $body) {
            new CreateTemplateAction(
                TemplateInput::from([
                    'app' => $lead->app,
                    'company' => $lead->company,
                    'user' => auth()->user(),
                    'name' => $name,
                    'template' => $body,
                ])
            )->execute();
        }
    }

    private function reachOut(Lead $lead): void
    {
        $agentType = AgentType::factory()
            ->withAppId($lead->app->getId())
            ->create([
                'name' => 'Sales Email (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesEmailEnvelopeNeuronAgentStub::class,
            ]);
        $agent = Agent::factory()
            ->withAppId($lead->app->getId())
            ->withCompanyId($lead->company->getId())
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
                recipient: 'prospect@kanvas.dev',
            )->execute();
        } catch (Throwable) {
            // Post-send hooks may throw in the test env; the faked mail dispatch runs first.
        }
    }

    private function sentBlank(): Blank
    {
        $sent = null;
        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification) use (&$sent): bool {
                $sent = $notification;

                return true;
            }
        );

        return $sent;
    }
}
