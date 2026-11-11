<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\BillAddresseeService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Tests\Scribe\ScribeTestCase;

/**
 * Fresh companies only: the remembered names are a company setting, which lives in shared Redis.
 */
class BillAddresseeTest extends ScribeTestCase
{
    /** @var list<Companies> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $company) {
            $company->del(BillAddresseeService::BILLING_NAMES_SETTING);
            $company->del(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value);
        }

        parent::tearDown();
    }

    public function test_the_same_name_with_another_legal_form_or_punctuation_is_this_company(): void
    {
        $company = $this->company('Kyanvasu Inc');
        $service = new BillAddresseeService();

        $this->assertTrue($service->isAddressedTo($company, 'Kyanvasu, Inc.'));
        $this->assertTrue($service->isAddressedTo($company, 'KYANVASU'));
        $this->assertFalse($service->isAddressedTo($company, 'HyperDraft, Inc.'));
    }

    public function test_the_tax_id_matches_even_when_the_name_does_not(): void
    {
        $company = $this->company('TEST COMPANY');
        $company->set(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, '130339767');

        $this->assertTrue(new BillAddresseeService()->isAddressedTo($company, 'MCTEKK SRL', '130-33976-7'));
    }

    public function test_a_name_the_person_confirmed_is_remembered(): void
    {
        $company = $this->company('TEST COMPANY');
        $service = new BillAddresseeService();
        $this->assertFalse($service->isAddressedTo($company, 'MCTEKK SRL'));

        $service->remember($company, 'MCTEKK SRL');

        $this->assertTrue($service->isAddressedTo($company, 'Mctekk S.R.L.'));
    }

    public function test_a_claimed_name_is_remembered_only_when_a_person_approves_the_bill(): void
    {
        $company = $this->company('TEST COMPANY');
        $service = new BillAddresseeService();
        $bill = new Bill(['metadata' => [BillAddresseeService::METADATA_KEY => [
            'billed_to' => 'MCTEKK SRL',
            'claimed' => BillAddresseeService::CLAIMED_SAME_COMPANY,
        ]]]);
        $bill->setRelation('company', $company);
        $anyway = new Bill(['metadata' => [BillAddresseeService::METADATA_KEY => ['billed_to' => 'Kyanvasu', 'claimed' => 'book_anyway']]]);
        $anyway->setRelation('company', $company);

        $service->rememberApproved($anyway);
        $this->assertFalse($service->isAddressedTo($company, 'Kyanvasu'), 'booking one anyway teaches nothing');

        $service->rememberApproved($bill);
        $this->assertTrue($service->isAddressedTo($company, 'MCTEKK SRL'));
    }

    public function test_an_invoice_for_another_of_the_persons_companies_names_that_company(): void
    {
        $current = $this->company('Kanvas Test Books');
        $other = $this->company('HyperDraft Inc');
        $other->associateUser(Auth::user(), StateEnums::YES->getValue(), $other->defaultBranch);

        $found = new BillAddresseeService()->otherCompanyOf(Auth::user(), $current, 'HyperDraft, Inc.');

        $this->assertSame($other->getId(), $found?->getId());
    }

    private function company(string $name): Companies
    {
        $company = Companies::factory()->create(['users_id' => Auth::id(), 'name' => $name, 'is_active' => 1]);
        $company->associateApp($this->kanvasApp);
        $this->made[] = $company;

        return $company;
    }
}
