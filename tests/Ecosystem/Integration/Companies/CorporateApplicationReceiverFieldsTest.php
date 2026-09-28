<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Tests\TestCase;

final class CorporateApplicationReceiverFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    public function testAReceiverWithoutConfigurationUsesTheDefaults(): void
    {
        $receiver = $this->receiver();

        $this->assertSame(Field::REQUIRED_FIELDS, Field::requiredFor($receiver));
        $this->assertSame(Field::COMPANY_FIELDS, Field::companyFieldsFor($receiver));
        $this->assertSame(Field::USER_PROFILE_FIELDS, Field::userFieldsFor($receiver));
        $this->assertSame(Field::REQUIRED_FIELDS, Field::requiredFor(null));
    }

    public function testTheReceiverConfiguresItsOwnFieldLists(): void
    {
        $receiver = $this->receiver();
        $receiver->set(Field::RECEIVER_REQUIRED_KEY, ['legal_name', 'rnc', 'fleet_size']);
        $receiver->set(Field::RECEIVER_COMPANY_KEY, 'legal_name, rnc, fleet_size');
        $receiver->set(Field::RECEIVER_USER_KEY, []);

        $this->assertSame(['legal_name', 'rnc', 'fleet_size'], Field::requiredFor($receiver));
        $this->assertSame(['legal_name', 'rnc', 'fleet_size'], Field::companyFieldsFor($receiver));
        $this->assertSame(Field::USER_PROFILE_FIELDS, Field::userFieldsFor($receiver));
    }

    public function testMissingReportsTheEmptyRequiredKeys(): void
    {
        $values = ['legal_name' => 'Empresa SRL', 'rnc' => ' ', 'contact_email' => null];

        $this->assertSame(['rnc', 'contact_email'], Field::missing(Field::REQUIRED_FIELDS, fn (string $key) => $values[$key] ?? null));
        $this->assertSame([], Field::missing(['legal_name'], fn (string $key) => $values[$key] ?? null));
    }

    private function receiver(): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        return LeadReceiver::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'name' => 'Receiver fields ' . fake()->word(),
            'source_name' => 'receiver-fields',
            'is_default' => 0,
        ]);
    }
}
