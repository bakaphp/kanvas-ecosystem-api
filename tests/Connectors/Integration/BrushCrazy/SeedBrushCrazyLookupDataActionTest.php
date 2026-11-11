<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Actions\SeedBrushCrazyLookupDataAction;
use Kanvas\Guild\Customers\Models\PeopleType;
use Tests\TestCase;

final class SeedBrushCrazyLookupDataActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testCustomerBecomesTheDefaultPeopleTypeWhenTheCompanyHasNone(): void
    {
        $company = Companies::factory()->create();

        $this->seedCustomerType($company);

        $this->assertTrue((bool) $this->customerType($company)->is_default);
    }

    /**
     * Regression: the seed always flagged Customer as default, giving a company that already ran
     * Kanvas CRM two default people types.
     */
    public function testAnExistingDefaultPeopleTypeIsKept(): void
    {
        $company = Companies::factory()->create();
        $lead = PeopleType::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Lead',
            'is_default' => true,
        ]);

        $this->seedCustomerType($company);

        $this->assertFalse((bool) $this->customerType($company)->is_default);
        $this->assertTrue((bool) $lead->fresh()->is_default);
        $this->assertSame(
            1,
            PeopleType::query()->where('companies_id', $company->getId())->where('is_default', true)->count()
        );
    }

    private function seedCustomerType(Companies $company): void
    {
        $action = new class (app(Apps::class), $company, auth()->user()) extends SeedBrushCrazyLookupDataAction {
            public function seedCustomer(): int
            {
                return $this->seedCustomerPeopleType();
            }
        };

        $action->seedCustomer();
    }

    private function customerType(Companies $company): PeopleType
    {
        return PeopleType::query()
            ->where('companies_id', $company->getId())
            ->where('name', 'Customer')
            ->firstOrFail();
    }
}
