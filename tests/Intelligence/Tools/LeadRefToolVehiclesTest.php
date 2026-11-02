<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class LeadRefToolVehiclesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testReturnsTheVehicleOfInterestAndTheTradeIn(): void
    {
        $lead = $this->createLead();
        $lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, ['year' => 2024, 'make' => 'Honda', 'model' => 'Civic']);
        $lead->set(LeadCustomFieldEnum::TRADE_IN->value, ['year' => 2018, 'make' => 'Nissan', 'model' => 'Altima']);

        $result = $this->tool()->__invoke(lead_id: $lead->getId());

        $this->assertSame(['year' => 2024, 'make' => 'Honda', 'model' => 'Civic'], $result['vehicle_interest']);
        $this->assertSame(['year' => 2018, 'make' => 'Nissan', 'model' => 'Altima'], $result['vehicle_trade_in']);
    }

    public function testALeadWithoutVehiclesReturnsNull(): void
    {
        $lead = $this->createLead();

        $result = $this->tool()->__invoke(lead_id: $lead->getId());

        $this->assertNull($result['vehicle_interest']);
        $this->assertNull($result['vehicle_trade_in']);
    }

    private function tool(): LeadRefTool
    {
        /** @var Users $user */
        $user = auth()->user();

        return new LeadRefTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function createLead(): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
