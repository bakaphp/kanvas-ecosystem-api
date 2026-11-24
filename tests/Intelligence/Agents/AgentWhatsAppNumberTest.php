<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\TestCase;

final class AgentWhatsAppNumberTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'workflow'];

    private Apps $testApp;
    private Companies $company;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
        $this->number = '+1809' . fake()->unique()->numerify('#######');
    }

    public function testAnAgentIsCreatedHoldingAWhatsAppNumber(): void
    {
        $formatted = '+1 (809) ' . substr($this->number, 5, 3) . '-' . substr($this->number, 8);

        $response = $this->saveAgent('createAiAgent(input: $input)', [
            'whatsapp_config' => ['phone_number' => $formatted],
        ]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame($this->number, $response->json('data.agent.whatsapp_config.phone_number'));
        $this->assertSame(
            $this->number,
            Agent::getByIdFromCompanyApp($response->json('data.agent.id'), $this->company, $this->testApp)->whatsappPhoneNumber()
        );
    }

    public function testANumberAnotherAgentHoldsIsRejectedOnCreate(): void
    {
        $holder = $this->agent($this->number);

        $response = $this->saveAgent('createAiAgent(input: $input)', [
            'whatsapp_config' => ['phone_number' => $this->number],
        ]);

        $this->assertStringContainsString('already held by agent ' . $holder->name, (string) $response->json('errors.0.message'));
    }

    public function testANumberAnotherAgentHoldsIsRejectedOnUpdate(): void
    {
        $this->agent($this->number);
        $agent = $this->agent();

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', [
            'whatsapp_config' => json_encode(['phone_number' => $this->number]),
        ]);

        $this->assertStringContainsString('already held by agent', (string) $response->json('errors.0.message'));
        $this->assertNull($agent->fresh()->whatsapp_config);
    }

    public function testTheHolderSavesItsOwnNumberAgain(): void
    {
        $agent = $this->agent($this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', [
            'whatsapp_config' => ['phone_number' => $this->number],
        ]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame($this->number, $agent->fresh()->whatsappPhoneNumber());
    }

    public function testAnUpdateThatOmitsWhatsappConfigKeepsIt(): void
    {
        $agent = $this->agent($this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', []);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame($this->number, $agent->fresh()->whatsappPhoneNumber());
    }

    public function testAnUpdateWithANullWhatsappConfigGivesTheNumberUp(): void
    {
        $agent = $this->agent($this->number);

        $response = $this->saveAgent('updateAiAgent(id: ' . $agent->getId() . ', input: $input)', ['whatsapp_config' => null]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertNull($agent->fresh()->whatsappPhoneNumber());
    }

    public function testTheOldestAgentOfTheCompanyOwnsANumberTwoWereGiven(): void
    {
        $formatted = '+1 (809) ' . substr($this->number, 5, 3) . '-' . substr($this->number, 8);
        $oldest = $this->agent($formatted);
        $this->agent($this->number);
        $integrationCompany = new IntegrationsCompany([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
        ]);

        $this->assertSame($oldest->getId(), Agent::owningWhatsAppNumber($integrationCompany, $this->number)?->getId());
        $this->assertNull(Agent::owningWhatsAppNumber($integrationCompany, '+18090000000'));

        $integrationCompany->companies_id = $this->company->getId() + 1_000_000;
        $this->assertNull(
            Agent::owningWhatsAppNumber($integrationCompany, $this->number),
            'another company\'s integration never reads this company\'s agents'
        );
    }

    private function saveAgent(string $field, array $input): TestResponse
    {
        return $this->graphQL('
            mutation($input: AgentAiInput!) {
                agent: ' . $field . ' {
                    id
                    whatsapp_config
                }
            }
        ', [
            'input' => [
                'agent_type_id' => AgentType::factory()->withAppId($this->testApp->getId())->create()->getId(),
                'name' => 'Agent ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                ...$input,
            ],
        ]);
    }

    private function agent(?string $whatsapp = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'whatsapp_config' => $whatsapp !== null ? ['phone_number' => $whatsapp] : null,
            ]);
    }
}
