<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ImportCorporateFleetAction;
use Kanvas\Connectors\Movipass\DataTransferObject\CorporateFleet;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class ImportCorporateFleetActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }
    }

    public function testImportsFleetVehiclesAsVehicleProducts(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $tagOne = (string) fake()->unique()->numerify('TAG######');
        $tagTwo = (string) fake()->unique()->numerify('TAG######');

        $fleet = CorporateFleet::fromImportArray([
            'company' => $this->companyBlock(),
            'vehicles' => [
                ['tag_number' => $tagOne, 'brand' => 'KIA', 'model' => 'PICANTO', 'year' => '2024', 'plate' => 'AA13021'],
                ['tag_number' => $tagTwo, 'marca' => 'NISSAN FRONTIER', 'year' => '2023', 'plate' => 'L453686'],
            ],
        ]);

        $result = new ImportCorporateFleetAction(
            app: $app,
            user: $user,
            fleet: $fleet,
            company: $company,
        )->execute();

        $this->assertFalse($result['dry_run']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);

        $picanto = Products::where('slug', 'kia-picanto-' . strtolower($tagOne))
            ->fromApp($app)
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($picanto);
        $this->assertStringContainsString('KIA', $picanto->name);
        $this->assertStringContainsString('PICANTO', $picanto->name);
        $this->assertSame('vehicle', $picanto->productsType?->slug);

        // The combined "marca" column must be split into brand + model.
        $frontier = Products::where('slug', 'nissan-frontier-' . strtolower($tagTwo))
            ->fromApp($app)
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($frontier);
        $this->assertStringContainsString('NISSAN', $frontier->name);
        $this->assertStringContainsString('FRONTIER', $frontier->name);
    }

    public function testReimportingTheSameFleetUpdatesInsteadOfDuplicating(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $tag = (string) fake()->unique()->numerify('TAG######');

        $payload = [
            'company' => $this->companyBlock(),
            'vehicles' => [
                ['tag_number' => $tag, 'brand' => 'TOYOTA', 'model' => 'HILUX', 'year' => '2022', 'plate' => 'A123456'],
            ],
        ];

        new ImportCorporateFleetAction(
            app: $app,
            user: $user,
            fleet: CorporateFleet::fromImportArray($payload),
            company: $company,
        )->execute();

        $second = new ImportCorporateFleetAction(
            app: $app,
            user: $user,
            fleet: CorporateFleet::fromImportArray($payload),
            company: $company,
        )->execute();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);

        $matches = Products::where('slug', 'toyota-hilux-' . strtolower($tag))
            ->fromApp($app)
            ->where('companies_id', $company->getId())
            ->count();

        $this->assertSame(1, $matches);
    }

    public function testDryRunValidatesWithoutWriting(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $tag = (string) fake()->unique()->numerify('TAG######');
        $slug = 'bmw-x7-' . strtolower($tag);

        $result = new ImportCorporateFleetAction(
            app: $app,
            user: $user,
            fleet: CorporateFleet::fromImportArray([
                'company' => $this->companyBlock(),
                'vehicles' => [
                    ['tag_number' => $tag, 'brand' => 'BMW', 'model' => 'X7', 'year' => '2020', 'plate' => 'G476106'],
                ],
            ]),
            company: $company,
            dryRun: true,
        )->execute();

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['importable']);

        $this->assertFalse(
            Products::where('slug', $slug)->fromApp($app)->where('companies_id', $company->getId())->exists()
        );
    }

    public function testFlagsMissingYearAndDuplicatePlatesInDryRun(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $result = new ImportCorporateFleetAction(
            app: $app,
            user: $user,
            fleet: CorporateFleet::fromImportArray([
                'company' => $this->companyBlock(),
                'vehicles' => [
                    ['tag_number' => (string) fake()->unique()->numerify('TAG######'), 'brand' => 'KIA', 'model' => 'K2700', 'plate' => 'PP081956'],
                    ['tag_number' => (string) fake()->unique()->numerify('TAG######'), 'brand' => 'SHACMAN', 'model' => 'VOLTEO', 'year' => '2025', 'plate' => 'PP081956'],
                ],
            ]),
            company: $company,
            dryRun: true,
        )->execute();

        $this->assertContains('PP081956', $result['duplicate_plates']);
        $this->assertTrue($result['vehicles'][0]['missing_year']);
        $this->assertFalse($result['vehicles'][1]['missing_year']);
    }

    public function testRejectsInvalidRncWhenCreatingCompany(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('RNC must be 9 or 11 digits');

        new ImportCorporateFleetAction(
            app: app(Apps::class),
            user: Auth::user(),
            fleet: CorporateFleet::fromImportArray([
                'company' => $this->companyBlock(['rnc' => '1234567']),
                'vehicles' => [
                    ['tag_number' => '999999', 'brand' => 'KIA', 'model' => 'SOLUTO'],
                ],
            ]),
        )->execute();
    }

    private function companyBlock(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Constructora Macdougall S.R.L',
            'commercial_name' => 'Macdougall',
            'rnc' => '131419951',
            'contact_name' => 'Megan Rosario',
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '8095551234',
        ], $overrides);
    }
}
