<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Commerce\ShoppingAssistantAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\AddToCartTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\FindMyOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\ListMyOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\RemoveFromCartTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\UpdateCartItemTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\ViewCartTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CaptureConversationLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\StopContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleInterestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\ListOpenSalesOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Souk\IssueCompanyCreditTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\Intelligence\Agents\Concerns\CreatesShopperFixtures;
use Tests\TestCase;

final class ShoppingAssistantAgentTest extends TestCase
{
    use CreatesShopperFixtures;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private function makeAgent(Users $agentUser, array $attributes = []): Agent
    {
        $agentType = AgentType::factory()->withAppId($this->apps->getId())->create([
            'provider' => 'neuron',
            'handler' => ShoppingAssistantAgent::class,
        ]);

        return Agent::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->create(array_merge([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $agentUser->getId(),
                'role' => [],
            ], $attributes));
    }

    public function testIsCustomerFacingSoThePublicChatGateAcceptsIt(): void
    {
        $handler = new ShoppingAssistantAgent();

        $this->assertInstanceOf(ConversesWithCustomer::class, $handler);
        $this->assertNotInstanceOf(ConversesWithUser::class, $handler);

        $agent = $this->makeAgent(Users::factory()->create());

        $this->assertTrue($agent->conversesWithCustomer());
    }

    public function testAnonymousShopperInstructionsCarryPersonaAndAskForOrderEmail(): void
    {
        $agentUser = Users::factory()->create(['firstname' => 'Sam', 'lastname' => 'Store']);
        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(agent: $this->makeAgent($agentUser), user: $this->user);

        $instructions = $handler->instructions();

        $this->assertStringContainsString('Sam Store', $instructions);
        $this->assertStringContainsString('NOT signed in', $instructions);
        $this->assertStringContainsString('find_my_order', $instructions);
        $this->assertStringContainsString('NEVER reveal internal system identifiers', $instructions);
        $this->assertStringNotContainsString((string) $agentUser->email, $instructions);
    }

    public function testIdentifiedShopperInstructionsNameThemAndUnlockOrderHistory(): void
    {
        $shopper = $this->makeShopper();
        $session = new Session([
            'entity_namespace' => People::class,
            'entity_id' => $shopper->getId(),
        ]);

        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(agent: $this->makeAgent(Users::factory()->create()), user: $this->user);
        $handler->setSession($session);

        $instructions = $handler->instructions();

        $this->assertStringContainsString($shopper->firstname, $instructions);
        $this->assertStringContainsString('signed in as', $instructions);
        $this->assertStringContainsString('list_my_orders', $instructions);
        $this->assertStringNotContainsString('NOT signed in', $instructions);
    }

    public function testRoleFromTheDatabaseOverridesTheLocalDefaults(): void
    {
        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(Users::factory()->create(), [
                'role' => ['background' => 'You sell artisan coffee beans.'],
            ]),
            user: $this->user,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString('artisan coffee beans', $instructions);
        $this->assertStringNotContainsString('shopping assistant: a knowledgeable', $instructions);
    }

    public function testDefaultInstructionsCarryTheCommerceSupportRules(): void
    {
        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(agent: $this->makeAgent(Users::factory()->create()), user: $this->user);

        $instructions = $handler->instructions();

        $this->assertStringContainsString('not a general-purpose chatbot', $instructions);
        $this->assertStringContainsString('Reply in the language the shopper writes in', $instructions);
        $this->assertStringContainsString('Label every estimate as an estimate', $instructions);
        $this->assertStringContainsString('never quietly swap the model', $instructions);
        $this->assertStringContainsString('Never build or guess a link yourself', $instructions);
        $this->assertStringContainsString('add_to_cart only after the shopper', $instructions);
        $this->assertStringContainsString('guarantee that a refund, return, cancellation or claim', $instructions);
        $this->assertStringContainsString('whether a delivery date is confirmed or estimated', $instructions);
        $this->assertStringContainsString('conversation_summary', $instructions);
        $this->assertStringContainsString('# TOOLS USAGE RULES', $instructions);
    }

    public function testRoleOverridesCannotRemoveTheGuardrailsOrToolRules(): void
    {
        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(Users::factory()->create(), [
                'role' => [
                    'background' => 'You sell artisan coffee beans.',
                    'steps' => 'Recommend a roast.',
                    'output' => 'One sentence.',
                ],
            ]),
            user: $this->user,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString('One sentence.', $instructions);
        $this->assertStringContainsString('full card number, security code, password, one-time code', $instructions);
        $this->assertStringContainsString('Never say you checked a product, price, order or system', $instructions);
        $this->assertStringContainsString('Never claim "best price" or "lowest price"', $instructions);
        $this->assertStringContainsString('# TOOLS USAGE RULES', $instructions);
    }

    public function testToolsetIsStorefrontNotDealerOrBackOffice(): void
    {
        $handler = new ShoppingAssistantAgent();
        $handler->setConfiguration(agent: $this->makeAgent(Users::factory()->create()), user: $this->user);

        $toolClasses = array_map('get_class', new ReflectionMethod($handler, 'tools')->invoke($handler));

        foreach ([
            FindMyOrderTool::class,
            ListMyOrdersTool::class,
            ViewCartTool::class,
            AddToCartTool::class,
            UpdateCartItemTool::class,
            RemoveFromCartTool::class,
            InventorySearchTool::class,
            VariantDetailTool::class,
            HandOffTool::class,
            StopContactTool::class,
            CaptureConversationLeadTool::class,
        ] as $expected) {
            $this->assertContains($expected, $toolClasses);
        }

        foreach ([
            VehicleInterestTool::class,
            FindSalesOrderTool::class,
            ListOpenSalesOrdersTool::class,
            IssueCompanyCreditTool::class,
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $toolClasses);
        }
    }
}
