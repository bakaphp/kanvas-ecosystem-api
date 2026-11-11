<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ToolInterface;
use Tests\Scribe\ScribeTestCase;

/**
 * Both accounting agents read inbound email, so plain email to a vendor or customer loads only while a real
 * person is asking: a forged inbound message must not be able to make the agent write to a third party.
 * Documents still travel only as engagements (send_invoice / send_quote); the email tool cannot attach one.
 */
final class AccountingAgentEmailToolsTest extends ScribeTestCase
{
    public function test_both_agents_email_only_while_a_person_is_asking(): void
    {
        foreach ([AccountsPayableAgent::make(), AccountsReceivableAgent::make()] as $handler) {
            $this->assertContains('send_person_email', $this->toolNames($handler, personAsking: true), $handler::class);
            $this->assertNotContains('send_person_email', $this->toolNames($handler, personAsking: false), $handler::class);
        }
    }

    public function test_the_ap_agent_can_name_a_vendor_contact(): void
    {
        $names = $this->toolNames(AccountsPayableAgent::make(), personAsking: false);

        foreach (['list_organization_people', 'create_person', 'link_person_to_organization'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    /**
     * The turn's actor is the agent's own user on inbound-email and scheduled turns, and a fresh human
     * otherwise.
     *
     * @return list<string>
     */
    private function toolNames(SystemUserAgent $handler, bool $personAsking): array
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create([
            'provider' => 'neuron',
            'handler' => $handler::class,
        ]);
        $user = Users::factory()->create();
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $type->getId(), ...($personAsking ? [] : ['user_id' => $user->getId()])]);
        $handler->setConfiguration(agent: $agent, user: $user);

        return array_values(array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($handler->getTools(), static fn (object $tool): bool => $tool instanceof ToolInterface),
        ));
    }
}
