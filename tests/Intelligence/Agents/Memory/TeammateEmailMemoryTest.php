<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use Tests\Stubs\Intelligence\RememberingSystemUserAgentStub;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;

/**
 * An email a teammate writes to an agent's mailbox runs as an AI identity, with the teammate as the
 * record the session is on. The memory is still the teammate's: it follows them to Slack and the web
 * chat, and their earlier conversations reach the email turn.
 */
class TeammateEmailMemoryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'crm', 'social'];

    private Users $max;

    private Users $agentUser;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        SharedCompanyMemory::reset();

        // Never the authenticated user: in CI it is also the company's AI agent user, which is the
        // one identity an email turn cannot be told apart from.
        $this->max = $this->register('Max');
        $this->agentUser = $this->register('Ginny');
        $this->agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['user_id' => $this->agentUser->getId()]);
    }

    public function testATeammatesEmailIsRememberedAsTheirs(): void
    {
        $this->emailFrom($this->max, 'The Globex renewal closes on Friday and legal still owes us the redline.');

        $this->assertSame($this->max->getId(), $this->rememberedUnder());
    }

    public function testWhatWasSaidByEmailFollowsTheTeammateToAnotherSurface(): void
    {
        $this->emailFrom($this->max, 'The Globex renewal closes on Friday and legal still owes us the redline.');

        $onSlack = $this->chatAs($this->max, 'What did we say by email about the renewal?');
        $someoneElse = $this->chatAs($this->register('Jenn'), 'What is going on with the renewal?');

        $this->assertStringContainsString('Globex', $onSlack);
        $this->assertStringNotContainsString('Globex', $someoneElse, 'an email is its sender\'s, like any conversation');
    }

    public function testAnEmailTurnRecallsWhatTheTeammateSaidElsewhere(): void
    {
        $this->chatAs($this->max, 'Keep in mind the Initech pilot starts in January and Stanley owns it.');

        $fromMax = $this->emailFrom($this->max, 'Who owns the pilot we talked about?');
        $fromJenn = $this->emailFrom($this->register('Jenn'), 'Who owns the pilot Max mentioned?');

        $this->assertStringContainsString('Initech', $fromMax);
        $this->assertStringNotContainsString('Initech', $fromJenn);
    }

    public function testAnEmailNeverGainsTheTeammatesRights(): void
    {
        $turn = $this->emailTurn($this->max);

        $this->assertTrue($this->agentUser->is($turn->requestingHuman()));
        $this->assertNull($turn->personAsking());
    }

    public function testAnExplicitHumanOwnsMemoryWhenTheSessionNamesSomeoneElse(): void
    {
        $turn = $this->emailTurn($this->register('Jenn'));
        $turn->setConversationHuman($this->max);
        $turn->chat(new UserMessage('The Globex renewal closes on Friday and legal still owes us the redline.'));

        $this->assertSame($this->max->getId(), $this->rememberedUnder());
    }

    public function testAContactsEmailIsNotATeammates(): void
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->agent->companies_id)
            ->create();

        $turn = new RememberingSystemUserAgentStub();
        $turn->setConfiguration(agent: $this->agent, entity: $person, user: $this->agentUser);
        $turn->setThreadId('contact-email');
        $turn->chat(new UserMessage('Please confirm you received the purchase order we sent on Monday morning.'));

        $this->assertSame($this->agentUser->getId(), $this->rememberedUnder());
    }

    private function emailTurn(Users $teammate): RememberingSystemUserAgentStub
    {
        $turn = new RememberingSystemUserAgentStub();
        $turn->setConfiguration(agent: $this->agent, entity: $teammate, user: $this->agentUser);
        $turn->setThreadId('email-' . $teammate->getId());

        return $turn;
    }

    private function emailFrom(Users $teammate, string $text): string
    {
        $turn = $this->emailTurn($teammate);
        $turn->chat(new UserMessage($text));

        return (string) end($turn->modelInputs);
    }

    private function chatAs(Users $human, string $text): string
    {
        $turn = new RememberingSystemUserAgentStub();
        $turn->setConfiguration(agent: $this->agent, user: $human);
        $turn->setThreadId('chat-' . $human->getId());
        $turn->chat(new UserMessage($text));

        return (string) end($turn->modelInputs);
    }

    private function rememberedUnder(): int
    {
        $turns = array_values(array_filter(
            SharedCompanyMemory::all(),
            fn (Document $document): bool => $document->getSourceType() === ConversationMemoryNode::SOURCE_TYPE
        ));

        $this->assertCount(1, $turns);

        return (int) $turns[0]->getMetadata()['users_id'];
    }

    private function register(string $firstname): Users
    {
        return new RegisterUsersAction(RegisterInput::from([
            'email' => fake()->unique()->safeEmail(),
            'password' => fake()->password(8),
            'firstname' => $firstname,
            'lastname' => fake()->lastName(),
        ]))->execute();
    }
}
