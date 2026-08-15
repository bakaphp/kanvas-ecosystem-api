<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Exporters;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Exporters\AffiliateCommissionsRecordExporter;
use Kanvas\Intelligence\Agents\Neuron\Exporters\RecordExporterRegistry;
use Kanvas\Souk\Affiliates\Models\Affiliate;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

final class AffiliateCommissionsRecordExporterTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    protected Apps $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testRegisteredInRegistry(): void
    {
        $this->assertContains('affiliate_commissions', new RecordExporterRegistry()->types());
    }

    public function testExportsCompanyConversionsAndFiltersByAffiliate(): void
    {
        // Per-run unique codes: unique_identifier (slug of name) is globally unique, and the assertions
        // must not be perturbed by conversions left by other affiliates/runs in the shared DB.
        $codeA = 'UAA' . uniqid();
        $codeB = 'UAB' . uniqid();

        $programId = $this->createProgram();
        $affiliateA = $this->createAffiliate($programId, $codeA . ' Distributor');
        $affiliateB = $this->createAffiliate($programId, $codeB . ' Distributor');

        $this->createConversion($affiliateA);
        $this->createConversion($affiliateA);
        $this->createConversion($affiliateB);

        $exporter = new AffiliateCommissionsRecordExporter();

        $rowsA = $exporter->rows($this->apps, $this->company, ['affiliate' => $codeA]);
        $this->assertCount(2, $rowsA);
        $this->assertCount(count($exporter->headers()), $rowsA[0]);

        // Case-insensitive match against the affiliate.
        $rowsALower = $exporter->rows($this->apps, $this->company, ['affiliate' => strtolower($codeA)]);
        $this->assertCount(2, $rowsALower);

        $rowsB = $exporter->rows($this->apps, $this->company, ['affiliate' => $codeB]);
        $this->assertCount(1, $rowsB);

        // No filter returns the whole company — at least the 3 just created.
        $all = $exporter->rows($this->apps, $this->company, []);
        $this->assertGreaterThanOrEqual(3, count($all));
    }

    public function testUnknownAffiliateThrows(): void
    {
        $exporter = new AffiliateCommissionsRecordExporter();

        $this->expectException(ValidationException::class);
        $exporter->rows($this->apps, $this->company, ['affiliate' => 'DOES-NOT-EXIST-' . uniqid()]);
    }

    public function testCommissionSurfacesEvenWhenAffiliateFiledUnderAnotherCompany(): void
    {
        $code = 'UAZ' . uniqid();
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId, $code . ' Distributor');
        $this->createConversion($affiliateId);

        // The order lives in this company; move the affiliate row to a different company. Because the
        // report anchors tenancy on the order, the commission must still surface.
        Affiliate::where('id', (int) $affiliateId)->update(['companies_id' => 999999]);

        $all = new AffiliateCommissionsRecordExporter()->rows($this->apps, $this->company, []);

        $mine = array_filter($all, fn (array $row): bool => str_contains((string) $row[3], $code));
        $this->assertCount(1, $mine);
    }

    private function createProgram(): string
    {
        return $this->graphQL('
            mutation($input: AffiliateProgramInput!) {
                createAffiliateProgram(input: $input) { id }
            }
        ', ['input' => ['name' => 'Program ' . uniqid(), 'default_commission_rate' => 10.00]])
            ->assertSuccessful()
            ->json('data.createAffiliateProgram.id');
    }

    private function createAffiliate(string $programId, string $name): string
    {
        return $this->graphQL('
            mutation($input: AffiliateInput!) {
                createAffiliate(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_program_id' => $programId,
            'name' => $name,
            'email' => fake()->unique()->email(),
            'commission_rate' => 10.00,
        ]])
            ->assertSuccessful()
            ->json('data.createAffiliate.id');
    }

    private function createConversion(string $affiliateId): void
    {
        $order = $this->createOrder();

        $this->graphQL('
            mutation($input: AffiliateConversionInput!) {
                createAffiliateConversion(input: $input) { id }
            }
        ', ['input' => [
            'affiliate_id' => $affiliateId,
            'order_id' => $order->getId(),
            'order_total' => 100.00,
            'eligible_amount' => 100.00,
            'commission_type' => 'PERCENTAGE',
            'commission_rate' => 10.00,
            'commission_amount' => 10.00,
        ]])->assertSuccessful();
    }

    private function createOrder(): Order
    {
        $people = People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();

        return Order::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create(['people_id' => $people->getId()]);
    }
}
