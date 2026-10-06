<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\RememberingSystemUserAgentStub;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * Internal memory is the company's, but a raw conversation is the person's: what Jenn told agent B
 * never reaches Max through agent A. What the company kept on purpose, a saved memory or a thing an
 * agent did, is shared.
 */
class InternalAgentMemoryPrivacyTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        SharedCompanyMemory::reset();
    }

    public function testAnotherPersonsConversationIsNotRecalledButSharedMemoryIs(): void
    {
        $app = app(Apps::class);
        $max = auth()->user();
        $company = $max->getCurrentCompany();
        $jenn = new RegisterUsersAction(RegisterInput::from([
            'email' => fake()->unique()->safeEmail(),
            'password' => fake()->password(8),
            'firstname' => 'Jenn',
            'lastname' => fake()->lastName(),
        ]))->execute();

        $agentA = $this->makeAgentFor($max);
        $agentB = $this->makeAgentFor($max);

        $jennsAgent = new RememberingSystemUserAgentStub();
        $jennsAgent->setConfiguration(agent: $agentB, user: $jenn);
        $jennsAgent->setThreadId('jenn-session');
        $jennsAgent->chat(new UserMessage('Between us: I am interviewing at Globex next week and have not told my manager yet.'));

        SharedCompanyMemory::store()->addDocument(new ConstantEmbeddingsProvider()->embedDocument(
            SharedCompanyMemory::document(
                'Acme prefers quarterly invoicing; monthly invoices get rejected.',
                'memory',
                $app->getId(),
                $company->getId(),
                extra: ['users_id' => $jenn->getId()],
                thread: 'jenn-session',
            )
        ));

        $maxsAgent = new RememberingSystemUserAgentStub();
        $maxsAgent->setConfiguration(agent: $agentA, user: $max);
        $maxsAgent->setThreadId('max-session');
        $maxsAgent->chat(new UserMessage('What do you know about Jenn, and how does Acme like to be invoiced?'));

        $prompt = (string) end($maxsAgent->systemPrompts);
        $this->assertStringNotContainsString('Globex', $prompt, "Jenn's conversation with another agent is hers");
        $this->assertStringContainsString('quarterly invoicing', $prompt, 'A memory saved for the company is shared');

        $jennAgain = new RememberingSystemUserAgentStub();
        $jennAgain->setConfiguration(agent: $agentA, user: $jenn);
        $jennAgain->setThreadId('jenn-later');
        $jennAgain->chat(new UserMessage('Remind me what I told you about next week?'));

        $this->assertStringContainsString('Globex', (string) end($jennAgain->systemPrompts), 'Her own conversation follows her to any agent');
    }
}
