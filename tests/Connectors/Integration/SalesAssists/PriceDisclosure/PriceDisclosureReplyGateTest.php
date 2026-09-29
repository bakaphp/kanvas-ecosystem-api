<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists\PriceDisclosure;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Enums\PriceDisclosureConfigurationEnum;
use Kanvas\Connectors\SalesAssist\PriceDisclosure\Services\PriceDisclosureReplyGate;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadHandOffNotification;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehiclePriceDisclosureTool;
use Tests\Stubs\Intelligence\PricedReplyNeuronAgentStub;
use Tests\TestCase;

final class PriceDisclosureReplyGateTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->kanvasApp = app(Apps::class);
        // A fresh company: the flag is a company setting, and the shared test company would carry
        // it into every other test that runs a kernel turn with a lead in scope.
        $this->company = Companies::factory()->create();
    }

    public function testASuppressedTurnIsDroppedAndTheMarkerIsCleared(): void
    {
        $lead = $this->makeLead();
        $lead->set(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value, 'price_missing');

        try {
            new PriceDisclosureReplyGate()->assertReplyAllowed($lead, 'Let me check the price and get back to you.', []);
            $this->fail('A suppressed turn must not ship.');
        } catch (AgentReplySkippedException $e) {
            $this->assertStringContainsString('price_missing', $e->getMessage());
        }

        $this->assertNull($lead->get(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value));
    }

    public function testAPriceThatDidNotComeFromTheToolIsSuppressedAndHandedOff(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead();

        try {
            new PriceDisclosureReplyGate()->assertReplyAllowed($lead, 'The MSRP is $54,995.', ['get_vehicle_interest:abc']);
            $this->fail('An unverified price must not ship.');
        } catch (AgentReplySkippedException $e) {
            $this->assertStringContainsString('price_unverified', $e->getMessage());
        }

        $this->assertSame(1, $this->handoffCount($lead));
        $this->assertNull($lead->get(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value));
    }

    public function testAPriceRenderedByTheToolPasses(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead();

        new PriceDisclosureReplyGate()->assertReplyAllowed(
            $lead,
            'Vehicle total price: $54,995.00.',
            [VehiclePriceDisclosureTool::NAME . ':' . sha1('{}')],
        );

        $this->assertSame(0, $this->handoffCount($lead));
    }

    public function testAReplyWithoutMoneyPasses(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead();

        new PriceDisclosureReplyGate()->assertReplyAllowed($lead, 'What time works for a test drive?', []);

        $this->assertSame(0, $this->handoffCount($lead));
    }

    public function testADealerOutsideTheRegimeIsNotGated(): void
    {
        $lead = $this->makeLead();

        new PriceDisclosureReplyGate()->assertReplyAllowed($lead, 'The MSRP is $54,995.', []);

        $this->assertSame(0, $this->handoffCount($lead));
    }

    public function testTheKernelDropsAnUnverifiedPriceBeforeAnythingIsPersisted(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead();

        $agentType = AgentType::factory()->withAppId($this->kanvasApp->getId())->create([
            'provider' => 'neuron',
            'handler' => PricedReplyNeuronAgentStub::class,
        ]);
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'is_active' => 1]);

        $this->expectException(AgentReplySkippedException::class);
        $this->expectExceptionMessage('price_unverified');

        new AgentChatKernel(
            agent: $agent,
            session: null,
            message: 'how much is the sierra?',
            user: auth()->user(),
            currentLead: $lead,
            persistConversation: false,
        )->execute();
    }

    public function testAPriceQuestionAboutTheLeadVehicleAnsweredWithoutTheToolIsSuppressed(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead(withVehicle: true);

        $this->assertGateSuppresses(
            $lead,
            inbound: 'How much is the Sierra?',
            response: 'Let me check the price and get back to you!',
            reason: 'disclosure_missing',
        );
    }

    public function testAPriceQuestionAnsweredThroughTheToolPasses(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead(withVehicle: true);

        new PriceDisclosureReplyGate()->assertReplyAllowed(
            $lead,
            'Vehicle total price: $54,995.00.',
            [VehiclePriceDisclosureTool::NAME . ':' . sha1('{}')],
            inboundText: 'How much is the Sierra?',
        );

        $this->assertSame(0, $this->handoffCount($lead));
    }

    public function testAPriceQuestionWithNoSpecificVehicleIsNotAFirstResponse(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead();

        new PriceDisclosureReplyGate()->assertReplyAllowed(
            $lead,
            'Prices depend on the trim, which one are you looking at?',
            [],
            inboundText: 'what are your prices like?',
        );

        $this->assertSame(0, $this->handoffCount($lead));
    }

    public function testAMonthlyPaymentQuestionIsSuppressedEvenWhenTheToolRan(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead(withVehicle: true);

        $this->assertGateSuppresses(
            $lead,
            inbound: '¿En cuánto me queda al mes?',
            response: 'Vehicle total price: $54,995.00.',
            reason: 'payment_unsupported',
            executedToolCalls: [VehiclePriceDisclosureTool::NAME . ':' . sha1('{}')],
        );
    }

    public function testAnAddOnQuestionIsSuppressed(): void
    {
        $this->company->set(PriceDisclosureConfigurationEnum::ENABLED->value, 1);
        $lead = $this->makeLead(withVehicle: true);

        $this->assertGateSuppresses(
            $lead,
            inbound: 'Is the protection package required?',
            response: 'It comes installed on every unit, so yes.',
            reason: 'add_on_disclosure_missing',
        );
    }

    public function testInboundIntentIsIgnoredForADealerOutsideTheRegime(): void
    {
        $lead = $this->makeLead(withVehicle: true);

        new PriceDisclosureReplyGate()->assertReplyAllowed(
            $lead,
            'Let me check the price and get back to you!',
            [],
            inboundText: 'How much is the Sierra?',
        );

        $this->assertSame(0, $this->handoffCount($lead));
    }

    private function assertGateSuppresses(
        Lead $lead,
        string $inbound,
        string $response,
        string $reason,
        array $executedToolCalls = [],
    ): void {
        try {
            new PriceDisclosureReplyGate()->assertReplyAllowed(
                $lead,
                $response,
                $executedToolCalls,
                inboundText: $inbound,
            );
            $this->fail("Expected the reply to be suppressed with {$reason}.");
        } catch (AgentReplySkippedException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }

        $this->assertSame(1, $this->handoffCount($lead));
        $this->assertNull($lead->get(PriceDisclosureConfigurationEnum::REPLY_SUPPRESSED->value));
    }

    private function handoffCount(Lead $lead): int
    {
        return LeadHandOffNotification::query()->where('leads_id', $lead->getId())->count();
    }

    private function makeLead(bool $withVehicle = false): Lead
    {
        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        if ($withVehicle) {
            $lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, [
                'make' => 'GMC',
                'model' => 'Sierra',
                'vin' => '1GTUUCED5PZ123456',
                'stockNumber' => 'STK-1',
            ]);
        }

        return $lead;
    }
}
