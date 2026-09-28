<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class SystemUserAgentTest extends TestCase
{
    private function buildHistory(SystemUserAgent $handler): AbstractChatHistory
    {
        /** @var AbstractChatHistory $history */
        $history = new ReflectionMethod($handler, 'chatHistory')->invoke($handler);

        return $history;
    }

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    public function testUsesInMemoryHistoryWhenUnconfigured(): void
    {
        $handler = new SystemUserAgent();

        $this->assertInstanceOf(InMemoryChatHistory::class, $this->buildHistory($handler));
        $this->assertFalse($handler->persistsTurnsToConversationStore());
    }

    public function testDirectUserConversationUsesPerSessionHistory(): void
    {
        $user = auth()->user();

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $user,
            user: $user,
        );

        $this->assertInstanceOf(KanvasMessageHistory::class, $this->buildHistory($handler));
        $this->assertTrue(
            $handler->persistsTurnsToConversationStore(),
            'Per-session history self-persists, so the agent reports it stores turns',
        );
    }

    public function testMentionOnCrmEntityUsesRollupHistory(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $people,
            user: $user,
        );

        $this->assertInstanceOf(SalesAssistKanvasMessageHistory::class, $this->buildHistory($handler));
        $this->assertFalse(
            $handler->persistsTurnsToConversationStore(),
            'The rollup history writes to Social; logTurn stays its usage record',
        );
    }

    public function testInstructionsInjectSubjectBriefForCrmEntity(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'firstname' => 'Zed',
        ]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $people,
            user: $user,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString("Who you're helping with right now", $instructions);
        $this->assertStringContainsString('People #' . $people->getId(), $instructions);
    }

    public function testInstructionsHaveNoBriefForDirectUserConversation(): void
    {
        $user = auth()->user();

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $user,
            user: $user,
        );

        $this->assertStringNotContainsString("Who you're helping with right now", $handler->instructions());
    }

    public function testInstructionsInjectTheAgentsOwnUserIdentity(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $agent, entity: $user, user: $user);

        $instructions = $handler->instructions();

        $this->assertStringContainsString('## Who you are', $instructions);
        $this->assertStringContainsString('You ARE a Kanvas user', $instructions);
        $this->assertStringContainsString((string) $user->email, $instructions);

        // The agent must know the exact @mention handle teammates reach it by.
        $handle = $user->getAppDisplayName();
        $this->assertNotSame('', $handle);
        $this->assertStringContainsString('@' . $handle, $instructions);
    }

    public function testResolvesRegisteredToolThatNeedsConstructorContext(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $agent, entity: $user, user: $user);

        $tool = new Tool();
        $tool->handler = CreateLeadTool::class;

        $resolved = new ReflectionMethod($handler, 'resolveRegisteredTool')->invoke($handler, $tool);

        $this->assertInstanceOf(
            CreateLeadTool::class,
            $resolved,
            'A context-needing registered tool must be instantiated with injected dependencies, not skipped',
        );
    }

    public function testResolvesRegisteredTraitToolAndFillsWithContext(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $agent, entity: $user, user: $user);

        $tool = new Tool();
        $tool->handler = FindProductTool::class; // uses HasKanvasContext (no-arg ctor)

        $resolved = new ReflectionMethod($handler, 'resolveRegisteredTool')->invoke($handler, $tool);

        $this->assertInstanceOf(FindProductTool::class, $resolved);

        // A HasKanvasContext tool has a no-arg constructor — the merge resolver must call
        // withContext() to fill it, otherwise the typed $app property stays uninitialized.
        $appProp = new ReflectionProperty($resolved, 'app');
        $this->assertTrue($appProp->isInitialized($resolved), 'withContext must have filled the tenant context');
        $this->assertSame($app->getId(), $appProp->getValue($resolved)->getId());
    }

    /**
     * Without a date in the prompt the model dates every tool call from its training cutoff — a
     * reporting agent then queries a range with no orders, reads the zero as a failed call, and
     * retries until Neuron aborts the turn (Sentry KANVAS-ECOSYSTEM-682).
     */
    public function testInstructionsGroundTheAgentOnTodaysDate(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $this->makeAgent(), entity: $user, user: $user);

        // Mirrors HasKanvasAgentBehavior::resolveTenantTimezone(): company, then user, then UTC.
        $timezone = 'UTC';
        foreach ([$company->timezone, $user->timezone] as $candidate) {
            if (is_string($candidate) && in_array($candidate, timezone_identifiers_list(), true)) {
                $timezone = $candidate;

                break;
            }
        }

        $instructions = $handler->instructions();

        $this->assertStringContainsString(
            'Current date: ' . Carbon::now($timezone)->format('l, Y-m-d'),
            $instructions,
        );
        $this->assertStringContainsString('do not use dates from your training data', $instructions);
    }
}
