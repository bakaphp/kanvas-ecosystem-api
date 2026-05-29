<?php

declare(strict_types=1);

namespace Tests\Inventory\Products;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ProductsRepositoryCorporateTagTest extends TestCase
{
    private Apps $kanvasApp;
    private Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
    }

    public function testExistsByAttributeValueInCompaniesFindsTagOwnedByAnyListedCompany(): void
    {
        $tag = $this->uniqueTag();
        $corpCompany = Companies::factory()->state(['users_id' => $this->kanvasUser->getId()])->create();
        $unrelated = Companies::factory()->state(['users_id' => $this->kanvasUser->getId()])->create();

        $vehicle = Products::factory()->state([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $corpCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
        ])->create();
        $vehicle->addAttributes($this->kanvasUser, [['name' => 'tag_number', 'value' => $tag]]);

        $this->assertTrue(ProductsRepository::existsByAttributeValueInCompanies(
            $this->kanvasApp,
            [$corpCompany->getId(), $unrelated->getId()],
            'tag-number',
            $tag,
        ));

        $this->assertFalse(ProductsRepository::existsByAttributeValueInCompanies(
            $this->kanvasApp,
            [$unrelated->getId()],
            'tag-number',
            $tag,
        ));
    }

    public function testExistsByAttributeValueInCompaniesReturnsFalseOnEmptyCompanyList(): void
    {
        $tag = $this->uniqueTag();
        $found = ProductsRepository::existsByAttributeValueInCompanies(
            $this->kanvasApp,
            [],
            'tag-number',
            $tag,
        );
        $this->assertFalse($found);
    }

    public function testExistsByAttributeValueInCompaniesIgnoresSoftDeletedProducts(): void
    {
        $tag = $this->uniqueTag();
        $company = Companies::factory()->state(['users_id' => $this->kanvasUser->getId()])->create();

        $vehicle = Products::factory()->state([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->kanvasUser->getId(),
        ])->create();
        $vehicle->addAttributes($this->kanvasUser, [['name' => 'tag_number', 'value' => $tag]]);

        $this->assertTrue(ProductsRepository::existsByAttributeValueInCompanies(
            $this->kanvasApp,
            [$company->getId()],
            'tag-number',
            $tag,
        ));

        $vehicle->is_deleted = 1;
        $vehicle->saveQuietly();

        $this->assertFalse(ProductsRepository::existsByAttributeValueInCompanies(
            $this->kanvasApp,
            [$company->getId()],
            'tag-number',
            $tag,
        ));
    }

    private function uniqueTag(): string
    {
        return (string) random_int(100000000000, 999999999999);
    }
}
